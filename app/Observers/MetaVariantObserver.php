<?php

namespace App\Observers;

use App\Jobs\Meta\SyncProductToMeta;
use App\Models\ProductVariant;
use App\Services\Meta\MetaSettings;

/**
 * Re-syncs the parent product whenever one of its variations changes (price,
 * stock, sku, attributes, active flag, image).
 */
class MetaVariantObserver
{
    public function __construct(private readonly MetaSettings $settings) {}

    private function dispatch(ProductVariant $variant): void
    {
        if ($this->settings->autoSyncEnabled() && $variant->product_id) {
            SyncProductToMeta::dispatch($variant->product_id, 'update');
        }
    }

    public function saved(ProductVariant $variant): void
    {
        $this->dispatch($variant);
    }

    public function deleted(ProductVariant $variant): void
    {
        $this->dispatch($variant);
    }
}
