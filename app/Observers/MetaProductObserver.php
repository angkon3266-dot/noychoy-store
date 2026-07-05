<?php

namespace App\Observers;

use App\Jobs\Meta\RemoveProductFromMeta;
use App\Jobs\Meta\SyncProductToMeta;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Meta\MetaSettings;

/**
 * Drives automatic catalog sync from the product lifecycle. Any create, update,
 * delete or restore queues a sync (or removal) for exactly that product — no
 * manual action required. The mapper's payload hash means unchanged products
 * are skipped before hitting the API, so "update" is safe to fire broadly.
 *
 * Registered in AppServiceProvider. Guarded by the auto-sync setting so it is a
 * no-op until the client enables and configures the integration.
 */
class MetaProductObserver
{
    public function __construct(private readonly MetaSettings $settings) {}

    private function enabled(): bool
    {
        return $this->settings->autoSyncEnabled();
    }

    public function created(Product $product): void
    {
        if ($this->enabled()) {
            SyncProductToMeta::dispatch($product->id, 'create');
        }
    }

    /** Columns whose change alone should NOT trigger a catalog sync. */
    private const IGNORED = ['views', 'loves_count', 'updated_at'];

    public function updated(Product $product): void
    {
        if (! $this->enabled()) {
            return;
        }

        // Skip high-frequency, catalog-irrelevant writes (e.g. the storefront
        // view counter) so we don't flood the queue on every page view.
        $meaningful = array_diff(array_keys($product->getChanges()), self::IGNORED);
        if (empty($meaningful)) {
            return;
        }

        // Covers price, sale price, stock, sku, name, description, category,
        // status (enable/disable), images-URL and every other field change.
        SyncProductToMeta::dispatch($product->id, 'update');
    }

    public function deleted(Product $product): void
    {
        if ($this->enabled()) {
            RemoveProductFromMeta::dispatch($product->id);
        }
    }

    public function restored(Product $product): void
    {
        if ($this->enabled()) {
            SyncProductToMeta::dispatch($product->id, 'update', true);
        }
    }

    /** Variant changes re-sync the parent product so the item group stays current. */
    public function syncFromVariant(ProductVariant $variant): void
    {
        if ($this->enabled() && $variant->product_id) {
            SyncProductToMeta::dispatch($variant->product_id, 'update');
        }
    }
}
