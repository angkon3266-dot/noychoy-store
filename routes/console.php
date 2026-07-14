<?php

use App\Jobs\Meta\RetryFailedMetaSyncs;
use App\Jobs\Meta\VerifyCatalogSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Meta catalog maintenance ────────────────────────────────────────────────
// Every minute: drain the Meta sync queue. This shared host has no long-running
// queue daemon, so without this the batch jobs dispatched by "Sync all" / "Full
// refresh" would sit in the `jobs` table forever. `--stop-when-empty` exits the
// moment the queue is clear (so it costs nothing when idle) and `--max-time` caps
// each run so it never overruns the next scheduler tick. The jobs' own tries()/
// backoff() still govern retries. An instant best-effort worker is also kicked on
// dispatch (MetaQueueRunner) — this is the guaranteed fallback.
Schedule::command('queue:work '.env('QUEUE_CONNECTION', 'database')
        .' --queue='.config('meta.sync.queue', 'default')
        .' --stop-when-empty --max-time=50 --sleep=1 --no-interaction')
    ->everyMinute()
    ->name('meta-queue-drain')
    ->withoutOverlapping();

// Hourly: re-queue any product stuck in a failed sync state.
Schedule::job(new RetryFailedMetaSyncs)->hourly()->name('meta-retry-failed')->withoutOverlapping();

// Daily: verify the whole catalog is in sync and re-queue anything stale.
Schedule::job(new VerifyCatalogSync)->dailyAt('03:30')->name('meta-verify-catalog')->withoutOverlapping();

// ── Member notifications ────────────────────────────────────────────────────
// Batched "new arrivals" announcement — sends one notification for the day's new
// products (a no-op when there are none). Adjust the time as you like.
Schedule::command('notifications:new-arrivals')->dailyAt('10:00')->name('notify-new-arrivals')->withoutOverlapping();

// Deliver any admin notifications that were scheduled for a future time.
Schedule::call(fn () => app(\App\Services\NotificationService::class)->deliverDue())
    ->everyFiveMinutes()->name('notify-deliver-scheduled');
