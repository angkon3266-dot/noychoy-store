<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the queue moving when the cron that runs the scheduler stops firing.
 *
 * Every queued job here — the order SMS and invoice email, the BDCourier
 * auto-check, Meta events and catalogue syncs — waits for the scheduler's
 * minutely `queue:work` (routes/console.php, DEPLOY.md §4), and the scheduler
 * only runs when cPanel's cron fires it. On 3 Oct 2026 that cron stopped and
 * nothing in the app noticed: 78 jobs sat for a day, and new customers'
 * courier history was never looked up. The host also disables exec(), so
 * MetaQueueRunner's detached worker could never step in.
 *
 * So the scheduler now leaves a heartbeat every minute, and after a web
 * response has gone out (LiteSpeed's finish-request — the visitor is not kept
 * waiting) a missing heartbeat lets that request work through the queue
 * itself: at most once a minute across the site, for at most ~45 seconds,
 * one job at a time (`--once`, so no worker signal handlers are installed in
 * the web process). While the cron is healthy this costs one cache read.
 *
 * It only covers the queue. Hourly and daily scheduled work (Steadfast sync,
 * abandoned-cart reminders, Meta verify) still needs the cron — the admin
 * shows a banner while it is missing.
 */
class QueueFallback
{
    public const HEARTBEAT = 'scheduler:heartbeat';

    /** How long without a heartbeat before the cron counts as missing. */
    public const STALE_AFTER = 180;

    private const LOCK = 'queue:web-drain';

    private const BUDGET_SECONDS = 45;

    /** The scheduler calls this every minute. */
    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), now()->addDay());
    }

    /** Seconds since the scheduler last ran, or null when it has not been seen. */
    public static function schedulerAge(): ?int
    {
        $last = Cache::get(self::HEARTBEAT);

        return $last ? max(0, now()->getTimestamp() - (int) $last) : null;
    }

    public static function schedulerRunning(): bool
    {
        $age = static::schedulerAge();

        return $age !== null && $age < self::STALE_AFTER;
    }

    /** After a web response: drain the queue in-process if the cron is missing. */
    public function afterResponse(): void
    {
        try {
            if (config('queue.default') !== 'database' || static::schedulerRunning()) {
                return;
            }

            // One drain per minute for the whole site, whichever request gets here first.
            if (! Cache::add(self::LOCK, 1, 60) || ! $this->hasWaitingJobs()) {
                return;
            }

            ignore_user_abort(true);
            @set_time_limit(self::BUDGET_SECONDS + 150);

            $deadline = microtime(true) + self::BUDGET_SECONDS;
            while (microtime(true) < $deadline && $this->hasWaitingJobs()) {
                Artisan::call('queue:work', [
                    'connection' => 'database',
                    '--queue' => config('meta.sync.queue', 'default'),
                    '--once' => true,
                    '--sleep' => 0,
                    '--no-interaction' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Queue fallback drain failed', ['error' => $e->getMessage()]);
        }
    }

    /** A job that is due and not held by a live worker. */
    private function hasWaitingJobs(): bool
    {
        $now = now()->getTimestamp();
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', config('meta.sync.queue', 'default'))
            ->where('available_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<=', $now - $retryAfter))
            ->exists();
    }
}
