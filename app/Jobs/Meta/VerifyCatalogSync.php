<?php

namespace App\Jobs\Meta;

use App\Models\MetaSyncState;
use App\Services\Meta\MetaCatalogService;
use App\Services\Meta\MetaSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily: verify the whole catalog is in sync. Any eligible product that is not
 * currently "synced" (never synced, pending, failed or stale) is re-queued.
 */
class VerifyCatalogSync implements ShouldQueue
{
    use Queueable;

    public function handle(MetaSettings $settings, MetaCatalogService $service): void
    {
        if (! $settings->autoSyncEnabled()) {
            return;
        }

        $service->eligibleQuery()
            ->select('id')
            ->chunkById(200, function ($products) {
                $ids = $products->pluck('id');

                // Which of these are already fully synced?
                $synced = MetaSyncState::whereIn('product_id', $ids)
                    ->where('status', MetaSyncState::STATUS_SYNCED)
                    ->pluck('product_id')
                    ->unique();

                $ids->reject(fn ($id) => $synced->contains($id))
                    ->each(fn ($id) => SyncProductToMeta::dispatch((int) $id, 'refresh', true));
            });
    }
}
