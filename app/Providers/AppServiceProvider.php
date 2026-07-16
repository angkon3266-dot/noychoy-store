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
        // ── Rate limiters for public storefront endpoints ────────────────────
        // OTP: strict — this sends a paid SMS. Limited per phone AND per IP so
        // neither a phone can be bombed nor one IP can drain SMS credit.
        \Illuminate\Support\Facades\RateLimiter::for('otp', function (\Illuminate\Http\Request $request) {
            // Key by the target identity (phone for SMS OTP, email for reset
            // links) so one account can't be bombed; fall back to IP.
            $target = bd_phone((string) $request->input('phone'))
                ?: strtolower(trim((string) $request->input('email')))
                ?: $request->ip();

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(2)->by('otp-t:'.$target),
                \Illuminate\Cache\RateLimiting\Limit::perHour(6)->by('otp-th:'.$target),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            ];
        });

        // Login: per account+IP so one attacker can't lock everyone out, with an
        // IP ceiling against distributed guessing.
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $who = bd_phone((string) $request->input('phone')) ?: (string) $request->input('email');

            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('login:'.$who.'|'.$request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        // Authorization: Super-Admin-only modules.
        Gate::define('meta.access', [MetaPolicy::class, 'access']);
        Gate::define('system-config.access', [SystemConfigPolicy::class, 'access']);

        // Automatic Meta catalog sync on product / variant lifecycle changes.
        Product::observe(MetaProductObserver::class);
        ProductVariant::observe(MetaVariantObserver::class);

        // Bust the cached homepage whenever its ingredients change.
        $bustHome = fn () => \Illuminate\Support\Facades\Cache::forget(\App\Http\Controllers\Shop\HomeController::CACHE_KEY);
        Product::saved($bustHome);
        Product::deleted($bustHome);
        Category::saved($bustHome);
        Category::deleted($bustHome);

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
