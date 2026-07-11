<?php

namespace App\Jobs\Meta;

use App\Services\Meta\MetaCatalogService;
use App\Services\Meta\MetaSettings;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync a chunk of products to the Meta catalog in ONE items_batch call. This is
 * the bulk path used by "Sync all" / "Full refresh": each job now covers many
 * products instead of one, so a catalog of N products becomes N/batch_size jobs
 * and N/batch_size API calls. Shares the exact retry/backoff/pause semantics of
 * {@see SyncProductToMeta} so the existing queue dashboard & progress are intact.
 */
class SyncCatalogChunkToMeta implements ShouldQueue
{
    use Batchable, Queueable;

    /** @param array<int,int> $productIds */
    public function __construct(
        public array $productIds,
        public bool $force = false,
    ) {
        $this->onQueue(config('meta.sync.queue'));
    }

    public function tries(): int
    {
        return (int) config('meta.sync.tries', 5);
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return (array) config('meta.sync.backoff', [60, 300, 900]);
    }

    public function handle(MetaCatalogService $service, MetaSettings $settings): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Soft pause: hold the chunk on the queue without failing it.
        if ($settings->get('queue_paused')) {
            $this->release(60);

            return;
        }

        $service->withAttempt($this->attempts())->syncChunk($this->productIds, $this->force);
    }
}
