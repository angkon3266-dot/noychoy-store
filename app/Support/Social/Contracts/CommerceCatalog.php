<?php

namespace App\Support\Social\Contracts;

use App\Models\Product;

/**
 * Provider-agnostic product-catalog sync. Meta's catalog implements this;
 * Google Merchant / TikTok Catalog / Pinterest can implement the same contract.
 */
interface CommerceCatalog
{
    /** Push a product (and variants) to the catalog. */
    public function syncProduct(Product $product, string $action = 'update', bool $force = false): void;

    /** Remove a product from the catalog. */
    public function removeProduct(Product $product): void;

    /** Whether a product is eligible given current settings. */
    public function shouldSync(Product $product): bool;
}
