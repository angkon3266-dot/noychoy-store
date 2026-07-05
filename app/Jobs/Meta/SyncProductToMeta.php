<?php

namespace App\Jobs\Meta;

use App\Models\Product;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync one product (and its variants) to the Meta catalog. Queued so it never
 * blocks a web request; retried with backoff on transient API failures.
 */
class SyncProductToMeta implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public int $productId,
        public string $action = 'update',
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

    /** Avoid piling up duplicate syncs for the same product in the queue. */
    public function uniqueId(): string
    {
        return 'meta-sync-'.$this->productId;
    }

    public function handle(MetaCatalogService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $product = Product::withTrashed()->with(['images', 'variants', 'category'])->find($this->productId);
        if (! $product) {
            return;
        }

        $service->withAttempt($this->attempts())->syncProduct($product, $this->action, $this->force);
    }
}
