<?php

namespace App\Jobs\Meta;

use App\Models\Product;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remove a product's items from the Meta catalog (product deleted or made
 * ineligible). Queued and retried on transient failures.
 */
class RemoveProductFromMeta implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $productId)
    {
        $this->onQueue(config('meta.sync.queue'));
    }

    public function tries(): int
    {
        return (int) config('meta.sync.tries', 5);
    }

    public function backoff(): array
    {
        return (array) config('meta.sync.backoff', [60, 300, 900]);
    }

    public function handle(MetaCatalogService $service): void
    {
        $product = Product::withTrashed()->find($this->productId);
        if (! $product) {
            return;
        }

        $service->withAttempt($this->attempts())->removeProduct($product, 'delete');
    }
}
