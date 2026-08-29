<?php

namespace App\Providers;

use App\Events\ConfigurationRestored;
use App\Http\Controllers\Shop\HomeController;
use App\Listeners\RebuildConfigurationCache;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Modules\Meta\Services\MetaDebug;
use App\Observers\MetaProductObserver;
use App\Observers\MetaVariantObserver;
use App\Policies\MetaPolicy;
use App\Policies\SystemConfigPolicy;
use App\Services\CartService;
use App\Services\MailConfigurator;
use App\Services\MemberPricingService;
use App\Services\Meta\Credentials\MetaCredentialResolver;
use App\Services\Meta\Credentials\SingleStoreCredentialResolver;
use App\Services\SystemConfig\ConfigApplier;
use App\Services\SystemConfig\SystemConfigRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CartService::class);

        // Gift-ladder collections resolve once per request, not once per
        // cart re-render.
        $this->app->singleton(\App\Support\GiftLadder::class);

        // One instance per request so member-discount usage is queried once even
        // when both the layout and the cart view ask for it.
        $this->app->singleton(MemberPricingService::class);

        // Shared config store (memoises reads for the request/worker lifecycle).
        $this->app->singleton(SystemConfigRepository::class);

        // Meta Debug Mode — one instance per request so the Request ID is stable.
        $this->app->singleton(MetaDebug::class);

        // Meta credentials are resolved through a contract, never read from a
        // global inside a service. Today one implementation answers "the store
        // on this install"; adding multi-tenancy means binding a per-merchant
        // resolver here and changing nothing else. See docs/META-MULTITENANCY.md.
        $this->app->singleton(
            MetaCredentialResolver::class,
            SingleStoreCredentialResolver::class,
        );
    }

    public function boot(): void
    {
        // Generate every link, asset and form action as https:// once HTTPS is
        // enforced. Without this a cached http:// APP_URL would have the site
        // link to itself over plain HTTP — one redirect per click, and mixed
        // -content warnings on any asset. Off outside production so local dev
        // on http://localhost still works.
        if (config('security.https.redirect')) {
            URL::forceScheme('https');
        }

        // ── Rate limiters for public storefront endpoints ────────────────────
        // OTP: strict — this sends a paid SMS. Limited per phone AND per IP so
        // neither a phone can be bombed nor one IP can drain SMS credit.
        RateLimiter::for('otp', function (Request $request) {
            // Key by the target identity (phone for SMS OTP, email for reset
            // links) so one account can't be bombed; fall back to IP.
            $target = bd_phone((string) $request->input('phone'))
                ?: strtolower(trim((string) $request->input('email')))
                ?: $request->ip();

            return [
                Limit::perMinute(2)->by('otp-t:'.$target),
                Limit::perHour(6)->by('otp-th:'.$target),
                Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            ];
        });

        // Login: per account+IP so one attacker can't lock everyone out, with an
        // IP ceiling against distributed guessing.
        RateLimiter::for('login', function (Request $request) {
            $who = bd_phone((string) $request->input('phone')) ?: (string) $request->input('email');

            return [
                Limit::perMinute(5)->by('login:'.$who.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        // Authorization: Super-Admin-only modules.
        Gate::define('meta.access', [MetaPolicy::class, 'access']);
        Gate::define('system-config.access', [SystemConfigPolicy::class, 'access']);

        // Automatic Meta catalog sync on product / variant lifecycle changes.
        Product::observe(MetaProductObserver::class);
        ProductVariant::observe(MetaVariantObserver::class);

        // Bust the cached homepage whenever its ingredients change.
        $bustHome = fn () => Cache::forget(HomeController::CACHE_KEY);
        Product::saved($bustHome);
        Product::deleted($bustHome);
        Category::saved($bustHome);
        Category::deleted($bustHome);

        // Rebuild caches after a configuration restore/import.
        Event::listen(ConfigurationRestored::class, RebuildConfigurationCache::class);

        // Apply admin-managed SMTP settings to the live mailer (overrides .env / cached config).
        app(MailConfigurator::class)->apply();

        // Apply DB-stored System Configuration as runtime overrides (fails safe).
        app(ConfigApplier::class)->apply();

        // Settings are held in memory for the life of the process. A web request
        // ends in milliseconds, but a queue worker lives for up to a minute and
        // would otherwise keep serving settings from before an admin's change.
        Queue::looping(fn () => Setting::flushMemo());

        // Shared data for the storefront layout (nav menu + cart badge).
        //
        // This pattern matches every shop.* partial, so the composer fires once
        // per view a page renders — a product page renders ~7. Building the data
        // once per request and reusing it turns those repeated category queries
        // into a single set.
        View::composer(['shop.*', 'components.shop.*', 'layouts.shop'], function ($view) {
            $shared = once(function () {
                $nav = Category::active()->whereNull('parent_id')
                    ->with(['children' => fn ($q) => $q->active()])
                    ->orderBy('position')->get();

                // Footer "Shop" column: admin-chosen categories in order, else the nav.
                $footerIds = collect(theme('footer_category_ids') ?? [])->map(fn ($i) => (int) $i)->filter();

                return [
                    'navCategories' => $nav,
                    'footerCategories' => $footerIds->isEmpty()
                        ? $nav
                        : Category::active()->whereIn('id', $footerIds)->get()
                            ->sortBy(fn ($c) => $footerIds->search($c->id))->values(),
                    'siteMenu' => site_menu(),
                ];
            });

            // The cart badge is NOT memoized — it changes within a request when
            // an item is added, and a stale count is visible to the customer.
            $view->with($shared + ['cartCount' => app(CartService::class)->count()]);
        });
    }
}
