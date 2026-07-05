<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\MetaSettingsRequest;
use App\Jobs\Meta\RemoveProductFromMeta;
use App\Jobs\Meta\SyncProductToMeta;
use App\Models\MetaSyncLog;
use App\Models\Product;
use App\Services\Meta\MetaCatalogService;
use App\Services\Meta\MetaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

/**
 * Admin UI + actions for the Meta Integration module: settings (both modes),
 * test connection, and the five sync operations. All heavy work is queued —
 * controllers only validate, dispatch and report. Access is gated by the
 * `admin` middleware (Super Admin only) + the secondary security wall.
 */
class MetaIntegrationController extends Controller
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaCatalogService $catalog,
    ) {}

    public function index(Request $request)
    {
        return view('admin.meta.index', [
            'settings' => $this->settings,
            'snapshot' => $this->settings->safeSnapshot(),
            'oauthConfigured' => filled(config('meta.oauth.app_id')) && filled(config('meta.oauth.app_secret')),
            'notifications' => $this->notifications(),
            'eligibleCount' => $this->settings->isConfigured() ? $this->catalog->eligibleQuery()->count() : 0,
            'batch' => $this->currentBatch(),
            'connectionResult' => $request->session()->get('meta_connection'),
        ]);
    }

    /** Save Development-Mode settings + behaviour toggles. */
    public function save(MetaSettingsRequest $request)
    {
        $data = $request->validated();

        $this->settings->update(array_merge(
            [
                'mode' => $data['mode'],
                'business_id' => $data['business_id'] ?? null,
                'catalog_id' => $data['catalog_id'] ?? null,
                'pixel_id' => $data['pixel_id'] ?? null,
            ],
            $request->booleanFlags(),
        ));

        // Only overwrite the token when a new one is actually supplied.
        if (filled($data['access_token'] ?? null)) {
            $this->settings->setToken($data['access_token']);
        }

        return back()->with('success', 'Meta settings saved.');
    }

    /** Run the full connection test and flash the structured result. */
    public function testConnection(Request $request)
    {
        $result = $this->catalog->testConnection();

        return back()->with('meta_connection', $result->toArray());
    }

    /** Switch between development and production mode (never touches data). */
    public function switchMode(Request $request)
    {
        $request->validate(['mode' => ['required', 'in:development,production']]);
        $this->settings->update(['mode' => $request->input('mode')]);

        return back()->with('success', 'Switched to '.$request->input('mode').' mode.');
    }

    /** Disconnect: wipe credentials + connection metadata. Keeps logs & states. */
    public function disconnect()
    {
        $this->settings->update([
            'enabled' => false,
            'business_id' => null,
            'catalog_id' => null,
            'token_encrypted' => null,
            'pixel_id' => null,
            'connected_business_name' => null,
            'connected_catalog_name' => null,
            'token_expires_at' => null,
            'last_connection_ok' => null,
        ]);

        return back()->with('success', 'Meta account disconnected. Sync history was preserved.');
    }

    // ── Sync actions (all queued) ──────────────────────────────────────────

    public function syncAll()
    {
        return $this->dispatchBatch('Sync all products', false);
    }

    public function fullRefresh()
    {
        return $this->dispatchBatch('Full catalog refresh', true);
    }

    public function syncSelected(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        foreach ($data['ids'] as $id) {
            SyncProductToMeta::dispatch((int) $id, 'update', true);
        }

        return back()->with('success', count($data['ids']).' product(s) queued for sync.');
    }

    public function syncSingle(Product $product)
    {
        SyncProductToMeta::dispatch($product->id, 'update', true);

        return back()->with('success', 'Queued “'.$product->name.'” for Meta sync.');
    }

    public function removeSingle(Product $product)
    {
        RemoveProductFromMeta::dispatch($product->id);

        return back()->with('success', 'Queued removal of “'.$product->name.'” from the catalog.');
    }

    /** JSON progress for the currently running batch (polled by the UI). */
    public function batchStatus()
    {
        $batch = $this->currentBatch();

        return response()->json($batch ?: ['running' => false]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function dispatchBatch(string $name, bool $force)
    {
        if (! $this->settings->isConfigured() || ! $this->settings->isEnabled()) {
            return back()->with('error', 'Enable and configure Meta Integration first.');
        }

        $ids = $this->catalog->eligibleQuery()->pluck('id');
        if ($ids->isEmpty()) {
            return back()->with('error', 'No eligible products to sync.');
        }

        $jobs = $ids->map(fn ($id) => new SyncProductToMeta((int) $id, 'sync_all', $force))->all();

        $batch = Bus::batch($jobs)
            ->name($name)
            ->onQueue(config('meta.sync.queue'))
            ->allowFailures()
            ->dispatch();

        $this->settings->update(['sync_batch_id' => $batch->id]);

        return back()->with('success', $ids->count().' products queued ('.$name.'). Progress is shown below.');
    }

    /** Normalised progress of the last dispatched batch, or null. */
    private function currentBatch(): ?array
    {
        $id = $this->settings->get('sync_batch_id');
        if (! $id) {
            return null;
        }

        $batch = Bus::findBatch($id);
        if (! $batch) {
            return null;
        }

        return [
            'running' => ! $batch->finished(),
            'name' => $batch->name,
            'total' => $batch->totalJobs,
            'pending' => $batch->pendingJobs,
            'failed' => $batch->failedJobs,
            'processed' => $batch->processedJobs(),
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
        ];
    }

    /**
     * Dashboard-style notifications derived from current state (no extra table).
     * @return array<int, array{type:string, message:string}>
     */
    private function notifications(): array
    {
        $out = [];

        if (! $this->settings->isEnabled()) {
            return $out;
        }

        if ($this->settings->get('last_connection_ok') === false) {
            $out[] = ['type' => 'error', 'message' => 'Last connection test failed — check your token and permissions.'];
        }

        if ($expires = $this->settings->get('token_expires_at')) {
            if (\Illuminate\Support\Carbon::parse($expires)->isPast()) {
                $out[] = ['type' => 'error', 'message' => 'Access token has expired. Generate a new System User token.'];
            } elseif (\Illuminate\Support\Carbon::parse($expires)->diffInDays(now()) <= 7) {
                $out[] = ['type' => 'warning', 'message' => 'Access token expires soon ('.\Illuminate\Support\Carbon::parse($expires)->diffForHumans().').'];
            }
        }

        $recentFailures = MetaSyncLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
        if ($recentFailures > 0) {
            $out[] = ['type' => 'warning', 'message' => "{$recentFailures} sync(s) failed in the last 24 hours. See Sync Logs."];
        }

        if ($last = $this->settings->get('last_sync_at')) {
            $out[] = ['type' => 'success', 'message' => 'Last successful sync '.\Illuminate\Support\Carbon::parse($last)->diffForHumans().'.'];
        }

        return $out;
    }
}
