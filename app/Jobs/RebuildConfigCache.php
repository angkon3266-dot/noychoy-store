<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Clears (and, when previously cached, rebuilds) the Laravel config cache after
 * a configuration restore/import so the new values are picked up cleanly.
 * Queued so it never blocks the admin request.
 */
class RebuildConfigCache implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        try {
            $wasCached = app()->configurationIsCached();
            Artisan::call('config:clear');
            if ($wasCached) {
                Artisan::call('config:cache');
            }
        } catch (\Throwable $e) {
            Log::warning('RebuildConfigCache failed', ['error' => $e->getMessage()]);
        }
    }
}
