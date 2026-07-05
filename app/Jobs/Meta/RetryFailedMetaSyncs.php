<?php

namespace App\Jobs\Meta;

use App\Models\MetaSyncState;
use App\Services\Meta\MetaSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: re-queue every product currently in a failed sync state. Idempotent —
 * SyncProductToMeta is unique per product so duplicates collapse.
 */
class RetryFailedMetaSyncs implements ShouldQueue
{
    use Queueable;

    public function handle(MetaSettings $settings): void
    {
        if (! $settings->autoSyncEnabled()) {
            return;
        }

        MetaSyncState::where('status', MetaSyncState::STATUS_FAILED)
            ->select('product_id')
            ->distinct()
            ->pluck('product_id')
            ->each(fn ($id) => SyncProductToMeta::dispatch((int) $id, 'update', true));
    }
}
