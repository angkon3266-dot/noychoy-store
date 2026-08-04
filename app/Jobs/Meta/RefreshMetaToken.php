<?php

namespace App\Jobs\Meta;

use App\Services\Meta\MetaTokenRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: refresh the OAuth connection token before it expires.
 *
 * A no-op on almost every run — {@see MetaTokenRefresher::dueForRefresh()}
 * only returns true in the last 14 days of a ~60-day token's life, so a
 * healthy connection sits untouched for ~46 days between refreshes. If a
 * refresh fails, the token is left as-is and this simply tries again the next
 * day — the daily cadence *is* the retry loop, right up until either it
 * succeeds or the token expires and the merchant has to reconnect.
 */
class RefreshMetaToken implements ShouldQueue
{
    use Queueable;

    public function handle(MetaTokenRefresher $refresher): void
    {
        if (! $refresher->dueForRefresh()) {
            return;
        }

        $refresher->refresh();
    }
}
