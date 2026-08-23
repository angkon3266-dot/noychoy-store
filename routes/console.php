<?php

use App\Jobs\Meta\RefreshMetaToken;
use App\Jobs\Meta\RetryFailedMetaSyncs;
use App\Jobs\Meta\VerifyCatalogSync;
use App\Services\NotificationService;
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

// Daily: renew the OAuth connection token in the last 14 days before it
// expires, so a merchant on "Connect with Facebook" never has to manually
// reconnect within a normal ~60-day window. No-op outside that window and
// for Development Mode / System User connections (see MetaTokenRefresher).
Schedule::job(new RefreshMetaToken)->dailyAt('02:45')->name('meta-token-refresh')->withoutOverlapping();

// ── Member notifications ────────────────────────────────────────────────────
// Batched "new arrivals" announcement — sends one notification for the day's new
// products (a no-op when there are none). Adjust the time as you like.
Schedule::command('notifications:new-arrivals')->dailyAt('10:00')->name('notify-new-arrivals')->withoutOverlapping();

// Deliver any admin notifications that were scheduled for a future time.
// Wrapped: a closure task runs inside schedule:run itself, so an exception here
// aborts the whole tick — taking the queue drain (order SMS, invoices, staff
// alerts) down with it. Report and carry on instead.
Schedule::call(function () {
    try {
        app(NotificationService::class)->deliverDue();
    } catch (Throwable $e) {
        report($e);
    }
})->everyFiveMinutes()->name('notify-deliver-scheduled');

// Win-back automation — re-engage lapsed members once a day (a no-op when the
// automation is off or nobody is due).
Schedule::command('crm:winback')->dailyAt('11:00')->name('crm-winback')->withoutOverlapping();

// Abandoned-cart web-push reminders — every 30 min, remind members who left
// items in their cart (once each; a no-op when off or nobody is due).
Schedule::command('push:abandoned-cart')->everyThirtyMinutes()->name('push-abandoned-cart')->withoutOverlapping();

// Scheduled drip campaigns — send any due steps (hourly).
Schedule::command('push:drip')->hourly()->name('push-drip')->withoutOverlapping();

// Post-delivery review requests — one daily pass. A no-op when the automation
// is off or nothing is past its delay window. 11:30 sits after crm:winback at
// 11:00 so the two paid-SMS automations do not contend.
Schedule::command('reviews:request')->dailyAt('11:30')->name('reviews-request')->withoutOverlapping();

// Nightly: age out diagnostic logs (visits, SMS receipts, Meta sync attempts).
// Runs at a quiet hour because the first pass over a long-neglected table is
// the expensive one. Retention windows live in config/retention.php.
Schedule::command('logs:prune')->dailyAt('04:10')->name('logs-prune')->withoutOverlapping();
