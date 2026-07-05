<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Observers\MetaProductObserver;
use App\Observers\MetaVariantObserver;
use App\Policies\MetaPolicy;
use App\Services\CartService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CartService::class);
    }

    public function boot(): void
    {
        // Authorization for the Meta Integration module (Super Admin only).
        Gate::define('meta.access', [MetaPolicy::class, 'access']);

        // Automatic Meta catalog sync on product / variant lifecycle changes.
        Product::observe(MetaProductObserver::class);
        ProductVariant::observe(MetaVariantObserver::class);

        // Apply admin-managed SMTP settings to the live mailer (overrides .env / cached config).
        app(\App\Services\MailConfigurator::class)->apply();

        // Shared data for the storefront layout (nav menu + cart badge).
        View::composer(['shop.*', 'components.shop.*', 'layouts.shop'], function ($view) {
            $view->with('navCategories', Category::active()->whereNull('parent_id')
                ->with(['children' => fn ($q) => $q->active()])
                ->orderBy('position')->get());
            $view->with('siteMenu', site_menu());
            $view->with('cartCount', app(CartService::class)->count());
        });
    }
}
