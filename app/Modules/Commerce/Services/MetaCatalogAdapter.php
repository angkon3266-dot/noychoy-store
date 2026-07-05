<?php

namespace App\Modules\Commerce\Services;

use App\Models\Product;
use App\Services\Meta\MetaCatalogService;
use App\Support\Social\Contracts\CommerceCatalog;

/**
 * Binds the provider-agnostic {@see CommerceCatalog} contract to the existing
 * Meta catalog sync engine. Other modules (Automation) and future providers
 * depend on the contract, never on MetaCatalogService directly.
 */
class MetaCatalogAdapter implements CommerceCatalog
{
    public function __construct(private readonly MetaCatalogService $catalog) {}

    public function syncProduct(Product $product, string $action = 'update', bool $force = false): void
    {
        $this->catalog->syncProduct($product, $action, $force);
    }

    public function removeProduct(Product $product): void
    {
        $this->catalog->removeProduct($product, 'delete');
    }

    public function shouldSync(Product $product): bool
    {
        return $this->catalog->shouldSync($product);
    }
}
