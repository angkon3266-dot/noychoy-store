<?php

namespace App\Modules\Commerce;

use App\Modules\Commerce\Services\MetaCatalogAdapter;
use App\Support\Modules\GenericModuleManifest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Social\Contracts\CommerceCatalog;

/**
 * Commerce module — product/catalog sync to Meta. Fully functional today
 * (reuses the existing catalog engine); registered as a module so it plugs into
 * the registry, permission registry and modular OAuth like every other module.
 */
class CommerceServiceProvider extends ModuleServiceProvider
{
    protected function manifest(): ModuleManifest
    {
        return new GenericModuleManifest([
            'key' => 'commerce',
            'name' => 'Commerce',
            'description' => 'Sync products, variants, inventory, prices, images and categories to your Meta catalog.',
            'icon' => 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12A1.125 1.125 0 0119.75 21H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 6.75h12.974c.576 0 1.059.435 1.119 1.007z',
            'provider' => 'meta',
            'scopes' => ['business_management', 'catalog_management'],
            'config_id' => config('meta.oauth.config_id'),
            'permissions' => ['commerce.access'],
            'route' => 'admin.meta.index',
            'available' => true,
        ]);
    }

    protected function bindings(): void
    {
        // The provider-agnostic contract → the Meta implementation.
        $this->app->bind(CommerceCatalog::class, MetaCatalogAdapter::class);
    }
}
