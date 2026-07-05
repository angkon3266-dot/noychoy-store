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
// Hourly: re-queue any product stuck in a failed sync state.
Schedule::job(new RetryFailedMetaSyncs)->hourly()->name('meta-retry-failed')->withoutOverlapping();

// Daily: verify the whole catalog is in sync and re-queue anything stale.
Schedule::job(new VerifyCatalogSync)->dailyAt('03:30')->name('meta-verify-catalog')->withoutOverlapping();
