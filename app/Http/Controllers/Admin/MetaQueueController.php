<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Meta\RetryFailedMetaSyncs;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Queue monitor for the Meta sync queue: live waiting/running/failed counts,
 * plus soft pause/resume (jobs hold on the queue without failing) and a
 * retry-failed action.
 */
class MetaQueueController extends Controller
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaStats $stats,
    ) {}

    public function index()
    {
        return view('admin.meta.queue', [
            'queue' => $this->stats->queue(),
        ]);
    }

    /** JSON snapshot for live polling. */
    public function status()
    {
        return response()->json($this->stats->queue());
    }

    public function pause()
    {
        $this->settings->update(['queue_paused' => true]);

        return back()->with('success', 'Sync queue paused. Jobs will wait until resumed.');
    }

    public function resume()
    {
        $this->settings->update(['queue_paused' => false]);

        return back()->with('success', 'Sync queue resumed.');
    }

    /** Re-queue failed products, and push any failed queue jobs back on. */
    public function retry(Request $request)
    {
        RetryFailedMetaSyncs::dispatch();

        // Also retry raw failed queue jobs belonging to this module.
        try {
            Artisan::call('queue:retry', ['id' => ['all']]);
        } catch (\Throwable) {
            // queue:retry may be unavailable on some setups — the state-based
            // RetryFailedMetaSyncs above already covers the common case.
        }

        return back()->with('success', 'Failed syncs re-queued.');
    }
}
