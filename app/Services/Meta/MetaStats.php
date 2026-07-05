<?php

namespace App\Services\Meta;

use App\Models\MetaSyncLog;
use App\Models\MetaSyncState;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the Meta dashboard, queue monitor and status panels.
 * Pure aggregation over the sync state/log tables and the queue tables — no API
 * calls, so it is always cheap and safe to render.
 */
class MetaStats
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaCatalogService $catalog,
    ) {}

    /** Product-level counts by Meta status, plus success rate & timing. */
    public function overview(): array
    {
        $byStatus = MetaSyncState::query()
            ->select('status', DB::raw('COUNT(DISTINCT product_id) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $synced = (int) ($byStatus[MetaSyncState::STATUS_SYNCED] ?? 0);
        $pending = (int) ($byStatus[MetaSyncState::STATUS_PENDING] ?? 0);
        $failed = (int) ($byStatus[MetaSyncState::STATUS_FAILED] ?? 0);

        $eligible = $this->settings->isConfigured() ? $this->catalog->eligibleQuery()->count() : 0;
        $tracked = (int) MetaSyncState::whereIn('status', [
            MetaSyncState::STATUS_SYNCED, MetaSyncState::STATUS_PENDING, MetaSyncState::STATUS_FAILED,
        ])->distinct('product_id')->count('product_id');
        $neverSynced = max(0, $eligible - $tracked);

        // Success rate over the last 7 days.
        $recent = MetaSyncLog::where('created_at', '>=', now()->subDays(7))
            ->whereIn('status', ['success', 'failed'])
            ->selectRaw("SUM(status = 'success') as ok, COUNT(*) as total")
            ->first();
        $successRate = ($recent && $recent->total > 0) ? round($recent->ok / $recent->total * 100) : null;

        return [
            'synced' => $synced,
            'pending' => $pending,
            'failed' => $failed,
            'never_synced' => $neverSynced,
            'eligible' => $eligible,
            'success_rate' => $successRate,
            'today' => (int) MetaSyncLog::whereDate('created_at', today())->where('status', 'success')->count(),
            'last_sync' => $this->settings->get('last_sync_at'),
            'avg_response_ms' => (int) round((float) MetaSyncLog::where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('execution_ms')->avg('execution_ms')),
        ];
    }

    /**
     * Queue health for the Meta sync queue (database driver). Completed isn't
     * tracked by the DB queue (jobs are deleted on success), so we approximate
     * "completed today" from successful sync logs.
     */
    public function queue(): array
    {
        $queue = config('meta.sync.queue', 'default');
        $metaJobs = fn () => DB::table('jobs')->where('queue', $queue);

        $waiting = (clone ($metaJobs()))->whereNull('reserved_at')->count();
        $running = (clone ($metaJobs()))->whereNotNull('reserved_at')->count();

        // Failed jobs belonging to this module (payload references our job
        // namespace). Avoid backslash-escaping quirks by matching loosely.
        $failed = DB::table('failed_jobs')
            ->where('payload', 'like', '%Jobs%Meta%')
            ->count();

        return [
            'waiting' => $waiting,
            'running' => $running,
            'failed' => $failed,
            'completed_today' => (int) MetaSyncLog::whereDate('created_at', today())->where('status', 'success')->count(),
            'paused' => (bool) $this->settings->get('queue_paused'),
            'queue_name' => $queue,
            'driver' => config('queue.default'),
        ];
    }

    /** Health indicators for the connection status panel. */
    public function health(): array
    {
        $tokenExpires = $this->settings->get('token_expires_at');
        $tokenStatus = match (true) {
            ! $this->settings->hasToken() => 'missing',
            $tokenExpires && \Illuminate\Support\Carbon::parse($tokenExpires)->isPast() => 'expired',
            $tokenExpires && \Illuminate\Support\Carbon::parse($tokenExpires)->diffInDays(now()) <= 7 => 'expiring',
            default => 'ok',
        };

        return [
            'token' => $tokenStatus,
            'token_expires_at' => $tokenExpires,
            'webhook_verified' => (bool) $this->settings->get('webhook_verified_at'),
            'graph_api' => $this->settings->get('last_connection_ok'),
            'queue_worker' => $this->queueWorkerLikelyRunning(),
        ];
    }

    /**
     * Heuristic: if there are reserved jobs OR a successful sync log in the last
     * few minutes, a worker is (probably) alive. Best-effort only.
     */
    private function queueWorkerLikelyRunning(): ?bool
    {
        $recentLog = MetaSyncLog::where('created_at', '>=', now()->subMinutes(10))->exists();
        if ($recentLog) {
            return true;
        }

        $waiting = DB::table('jobs')->where('queue', config('meta.sync.queue', 'default'))->count();

        // Jobs piling up with none recently processed suggests no worker.
        return $waiting > 0 ? false : null; // null = unknown (idle)
    }
}
