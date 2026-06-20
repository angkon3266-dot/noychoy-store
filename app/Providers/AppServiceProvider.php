<?php

namespace App\Providers;

use App\Models\Category;
use App\Services\CartService;
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
