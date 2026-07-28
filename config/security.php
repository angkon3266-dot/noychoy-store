<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | The site sits behind Cloudflare, which forwards the *visitor's* original
    | scheme in X-Forwarded-Proto. bootstrap/app.php trusts Cloudflare's ranges,
    | so $request->isSecure() reports what the visitor used — not the scheme of
    | the Cloudflare→origin hop. That distinction is what makes this loop-proof:
    | under Flexible SSL the origin leg is plain HTTP by design, and a naive
    | $_SERVER['HTTPS'] check would redirect forever.
    |
    | Cloudflare's own "Always Use HTTPS" should also be on; this is the origin
    | -side backstop for requests that reach the server another way.
    |
    */

    'https' => [
        'redirect' => (bool) env('FORCE_HTTPS', env('APP_ENV') === 'production'),

        // Paths that must answer on whatever scheme they were called with.
        // Uptime probes and courier/Meta webhooks are the usual candidates —
        // a redirect turns a monitor green-light into a 301 it may not follow.
        'except' => array_filter(explode(',', (string) env('FORCE_HTTPS_EXCEPT', 'up'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response headers
    |--------------------------------------------------------------------------
    |
    | Strict-Transport-Security is deliberately absent: Cloudflare already sends
    | it, and two HSTS headers on one response is worse than one. Turn this on
    | only if the edge stops doing it.
    |
    */

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',

        // The storefront is never framed by a third party, and the admin least
        // of all. CSP frame-ancestors below says the same thing for modern
        // browsers; this covers the ones that only read the old header.
        'X-Frame-Options' => 'SAMEORIGIN',

        // Send the full URL to ourselves, origin-only to other HTTPS sites,
        // nothing at all when downgrading. Keeps Meta/Google attribution
        // working — they only need the origin — without leaking cart or
        // account paths into someone else's analytics.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // Only features the site genuinely never uses are switched off. The
        // ones the video embeds rely on — autoplay, fullscreen, encrypted-media,
        // picture-in-picture, gyroscope, accelerometer — are left untouched on
        // purpose, so listing a product video keeps behaving exactly as it does
        // now. Add to this list only after checking nothing calls the API.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), midi=(), serial=()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Every host below is here because something in this application actually
    | loads it — the inventory is in the comments so the next person can tell
    | what is still needed. Anything not listed is blocked, which is the point:
    | an injected <script src="evil.example"> stops being an option.
    |
    | 'unsafe-inline' and 'unsafe-eval' on script-src are not an oversight.
    | Alpine evaluates its x-* expressions with new Function(), and the Pixel
    | loader, Alpine component data and push-subscription code are all inline
    | in Blade. Removing either would take a nonce pass over every view and a
    | switch to Alpine's CSP build — a rewrite, not a hardening step. What the
    | policy still buys with them in place is real: no third-party script host,
    | no <object>/<embed>, no <base> takeover, no form posting off-site, no
    | framing by anyone else.
    |
    */

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),

        // Send Content-Security-Policy-Report-Only instead of the enforcing
        // header. Violations show in the browser console and nothing breaks —
        // the way to trial a change on live traffic before committing to it.
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        'directives' => [
            'default-src' => ["'self'"],

            'script-src' => [
                "'self'",
                "'unsafe-inline'",              // inline Blade <script> blocks
                "'unsafe-eval'",                // Alpine's expression evaluator
                'https://connect.facebook.net', // Meta Pixel (fbevents.js)
                'https://cdn.jsdelivr.net',     // JsBarcode, admin shipping labels
            ],

            'style-src' => [
                "'self'",
                "'unsafe-inline'",              // Blade style="" and theme CSS vars
                'https://fonts.googleapis.com', // admin-selected Google Fonts
            ],

            'font-src' => [
                "'self'",
                'data:',
                'https://fonts.gstatic.com',    // the files Google Fonts CSS points at
            ],

            // Product photography can be a remote URL (ProductImage::url passes
            // http/https paths straight through), Meta's <noscript> pixel is an
            // image, YouTube thumbnails come from i.ytimg.com, and the admin
            // previews uploads from blob:/data: before they are saved. Naming
            // hosts here would break the next merchant who pastes a CDN link,
            // so images are allowed over HTTPS generally — the weakest of the
            // directives, and also the least useful to an attacker.
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],

            'media-src' => ["'self'", 'data:', 'blob:'],

            'connect-src' => [
                "'self'",
                'https://www.facebook.com',     // Pixel event delivery
                'https://connect.facebook.net',
            ],

            'frame-src' => [
                "'self'",
                'https://www.youtube.com',      // product / home-block videos
                'https://www.youtube-nocookie.com',
                'https://player.vimeo.com',
            ],

            'worker-src' => ["'self'", 'blob:'], // service worker (push + PWA)

            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ],

        // Hosts to append to script-src, connect-src, frame-src and img-src
        // without touching this file — for a live-chat widget, a TikTok pixel,
        // or anything a merchant pastes into a custom HTML block. Space
        // separated, e.g. CSP_EXTRA_HOSTS="https://static.tawk.to https://*.tawk.to"
        'extra_hosts' => array_values(array_filter(
            preg_split('/\s+/', (string) env('CSP_EXTRA_HOSTS', '')) ?: []
        )),

        // Upgrade any stray http:// subresource to https:// rather than letting
        // the browser block it as mixed content. Off if a legacy remote image
        // host genuinely has no TLS.
        'upgrade_insecure_requests' => (bool) env('CSP_UPGRADE_INSECURE', true),
    ],
];
