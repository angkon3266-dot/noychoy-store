<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Events\ConfigurationRestored;
use App\Listeners\RebuildConfigurationCache;
use App\Observers\MetaProductObserver;
use App\Observers\MetaVariantObserver;
use App\Policies\MetaPolicy;
use App\Policies\SystemConfigPolicy;
use App\Services\CartService;
use App\Services\SystemConfig\ConfigApplier;
use App\Services\SystemConfig\SystemConfigRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CartService::class);

        // One instance per request so member-discount usage is queried once even
        // when both the layout and the cart view ask for it.
        $this->app->singleton(\App\Services\MemberPricingService::class);

        // Shared config store (memoises reads for the request/worker lifecycle).
        $this->app->singleton(SystemConfigRepository::class);

        // Meta Debug Mode — one instance per request so the Request ID is stable.
        $this->app->singleton(\App\Modules\Meta\Services\MetaDebug::class);
    }

    public function boot(): void
    {
        // Authorization: Super-Admin-only modules.
        Gate::define('meta.access', [MetaPolicy::class, 'access']);
        Gate::define('system-config.access', [SystemConfigPolicy::class, 'access']);

        // Automatic Meta catalog sync on product / variant lifecycle changes.
        Product::observe(MetaProductObserver::class);
        ProductVariant::observe(MetaVariantObserver::class);

        // Rebuild caches after a configuration restore/import.
        Event::listen(ConfigurationRestored::class, RebuildConfigurationCache::class);

        // Apply admin-managed SMTP settings to the live mailer (overrides .env / cached config).
        app(\App\Services\MailConfigurator::class)->apply();

        // Apply DB-stored System Configuration as runtime overrides (fails safe).
        app(ConfigApplier::class)->apply();

        // Map admin-managed courier logins onto the fraud-checker package config
        // so it uses DB credentials instead of .env. Fails safe (e.g. during
        // migrations, before the settings table exists).
        try {
            foreach (app(\App\Services\FraudChecker\FraudCheckerSettings::class)->credentials() as $courier => $fields) {
                foreach ($fields as $key => $value) {
                    if (filled($value)) {
                        config()->set("fraud-checker-bd-courier.{$courier}.{$key}", $value);
                    }
                }
            }
        } catch (\Throwable) {
            // Settings unavailable — the package simply falls back to its defaults.
        }

        // Shared data for the storefront layout (nav menu + cart badge).
        View::composer(['shop.*', 'components.shop.*', 'layouts.shop'], function ($view) {
            $nav = Category::active()->whereNull('parent_id')
                ->with(['children' => fn ($q) => $q->active()])
                ->orderBy('position')->get();
            $view->with('navCategories', $nav);

            // Footer "Shop" column: admin-chosen categories in order, else the nav.
            $footerIds = collect(theme('footer_category_ids') ?? [])->map(fn ($i) => (int) $i)->filter();
            $view->with('footerCategories', $footerIds->isEmpty()
                ? $nav
                : Category::active()->whereIn('id', $footerIds)->get()
                    ->sortBy(fn ($c) => $footerIds->search($c->id))->values());

            $view->with('siteMenu', site_menu());
            $view->with('cartCount', app(CartService::class)->count());
        });
    }
}
