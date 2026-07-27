<?php

namespace App\Jobs\Meta;

use App\Models\MetaSyncState;
use App\Services\Meta\MetaSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: re-queue products whose last sync failed. Idempotent —
 * SyncProductToMeta is unique per product so duplicates collapse.
 *
 * Bounded on purpose. This used to re-queue EVERY failed product every hour,
 * forever: when the Graph API rate-limits a catalogue (error #80014) the retry
 * fails again, stays failed, and is retried next hour — an endless loop that
 * writes one meta_sync_logs row per product per hour and never converges.
 *
 * A failure that is still failing a day later is not a transient error, so
 * retrying stops there and the product simply stays marked failed until someone
 * hits "Retry failed products". The batch is capped so one tick can't flood the
 * queue either.
 */
class RetryFailedMetaSyncs implements ShouldQueue
{
    use Queueable;

    /** Stop retrying a failure older than this. */
    public const GIVE_UP_AFTER_HOURS = 24;

    /** Most products re-queued in a single tick. */
    public const MAX_PER_RUN = 100;

    public function handle(MetaSettings $settings): void
    {
        if (! $settings->autoSyncEnabled()) {
            return;
        }

        MetaSyncState::where('status', MetaSyncState::STATUS_FAILED)
            ->where('updated_at', '>=', now()->subHours(self::GIVE_UP_AFTER_HOURS))
            ->select('product_id')
            ->distinct()
            ->limit(self::MAX_PER_RUN)
            ->pluck('product_id')
            ->each(fn ($id) => SyncProductToMeta::dispatch((int) $id, 'update', true));
    }
}
