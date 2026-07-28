<?php

return [
    // Meta (Facebook) Pixel + Conversions API.
    'pixel_id' => env('META_PIXEL_ID'),

    // Conversions API (server-side). Token kept in .env for security.
    'capi_enabled' => filter_var(env('META_CAPI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'access_token' => env('META_CAPI_TOKEN'),
    'test_event_code' => env('META_TEST_EVENT_CODE'), // optional, for Events Manager testing

    // In production a test event code applies ONLY to the admin's Test panel.
    // Real traffic ignores it, so a code left behind after debugging can't
    // quietly divert live events into the Test Events tab (where they don't
    // feed attribution or optimisation). Set true to opt real events back in.
    'test_events_in_production' => filter_var(env('META_TEST_EVENTS_IN_PRODUCTION', false), FILTER_VALIDATE_BOOL),
    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),

    /*
    |--------------------------------------------------------------------------
    | Commerce Manager / Catalog integration (product sync)
    |--------------------------------------------------------------------------
    |
    | Per-store credentials (business id, catalog id, system-user token, pixel)
    | are NOT stored here — they live encrypted in the settings table so each
    | client enters their own via the admin UI (App\Services\Meta\MetaSettings).
    | Only values that are identical for every install of this software live
    | here, plus the vendor's OAuth App credentials read from the environment.
    |
    */

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    // OAuth ("Connect with Facebook") — the vendor's Meta App credentials.
    // Leave blank to offer Development Mode (manual token) only.
    //
    // Catalog/Business permissions are granted through a *Facebook Login for
    // Business* configuration (config_id) — NOT via the `scope` param, which
    // only accepts standard Login permissions. Requesting catalog_management /
    // business_management as raw scopes triggers Meta's "Invalid Scopes" error,
    // so the standard-login fallback below requests only public_profile.
    'oauth' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'config_id' => env('META_LOGIN_CONFIG_ID'), // Facebook Login for Business config
        // Comma-separated valid standard-login scopes (fallback when no config_id).
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('META_OAUTH_SCOPES', 'public_profile')),
        ))),
    ],

    // Per-module Facebook Login for Business config ids (read via config so they
    // survive `config:cache` — env() outside config returns null once cached).
    'module_login' => [
        'analytics' => env('META_LOGIN_CONFIG_ID_ANALYTICS'),
        'inbox' => env('META_LOGIN_CONFIG_ID_INBOX'),
        'publishing' => env('META_LOGIN_CONFIG_ID_PUBLISHING'),
    ],

    // Webhook verify token (also pasted into the Meta App webhook configuration).
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    // Meta Integration Debug Mode — verbose Graph API logging + the admin debug
    // page. Always on locally; elsewhere only when META_DEBUG=true.
    'debug' => filter_var(env('META_DEBUG', false), FILTER_VALIDATE_BOOL),

    // Sync tuning.
    'sync' => [
        'batch_size' => (int) env('META_SYNC_BATCH_SIZE', 50),
        'queue' => env('META_SYNC_QUEUE', 'default'),
        'tries' => (int) env('META_SYNC_TRIES', 5),
        'backoff' => [60, 300, 900, 1800], // seconds between retries
        // Optional path to a PHP *CLI* binary for the instant background worker
        // (MetaQueueRunner). Leave null to use `php` on the PATH. Only needed if
        // the host's default `php` isn't the right CLI version.
        'worker_php' => env('META_SYNC_WORKER_PHP'),
    ],

    // Secondary security gate (extra password wall on the Meta menu).
    'security' => [
        'max_attempts' => 5,
        'lockout_minutes' => 15,
        'session_ttl' => (int) env('META_UNLOCK_TTL', 120), // minutes an unlock lasts
    ],

    // Catalog field defaults.
    'defaults' => [
        'condition' => 'new',
        'currency' => env('META_CURRENCY', 'BDT'),
        // Only an explicit override lives here. Left unset, the brand falls back
        // to store_name() (the admin's own store name) — APP_NAME is the wrong
        // source, it's often unset in production and leaks "Laravel" or a
        // previous store's name into all 100+ catalogue items.
        'brand' => env('META_DEFAULT_BRAND'),
        'google_product_category' => env('META_GOOGLE_CATEGORY'),

        // ISO 3166-1 alpha-2, used for user_data.country on server events.
        // Left unset rather than guessed: this codebase runs more than one
        // store, and a wrong country hash matches nobody.
        'country' => env('META_DEFAULT_COUNTRY'),
    ],
];
