<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\MetaSecurityGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'meta.gate' => MetaSecurityGate::class,
        ]);

        // First-party storefront traffic tracking (dashboard visitors + funnel).
        $middleware->web(append: [\App\Http\Middleware\TrackVisit::class]);

        // External webhooks post without a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // The site sits behind Cloudflare, so PHP's REMOTE_ADDR is a Cloudflare
        // edge IP. Without trusting those proxies, every visitor shares one
        // rate-limit bucket (throttle:5,1 on checkout 429'd real customers) and
        // logs/Meta CAPI receive edge IPs. Trusting only Cloudflare's published
        // ranges keeps X-Forwarded-For unspoofable for direct-to-origin
        // requests. Source: https://www.cloudflare.com/ips/
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Appended, never prepended: the global stack starts with TrustProxies,
        // and ForceHttps must run after it or $request->isSecure() would read
        // the Cloudflare→origin hop instead of the visitor's scheme and send
        // every HTTPS visitor into a redirect loop. SecurityHeaders sits in the
        // global stack so error and non-web responses are covered too.
        $middleware->append([
            \App\Http\Middleware\ForceHttps::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            return $request->is('admin', 'admin/*')
                ? route('admin.login')
                : route('customer.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Answer in the format the caller asked for. Restricting this to api/*
        // (the skeleton default) meant a failed AJAX call anywhere else — the
        // admin's inline price edit, thank-you card messages, push subscribe —
        // came back as an HTML redirect the JavaScript couldn't read, so the
        // UI could only say "not saved" instead of naming the problem.
        //
        // Browsers posting normal forms send Accept: text/html and are
        // unaffected: they still get the redirect-with-errors they expect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
