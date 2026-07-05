<?php

return [
    // Meta (Facebook) Pixel + Conversions API.
    'pixel_id' => env('META_PIXEL_ID'),

    // Conversions API (server-side). Token kept in .env for security.
    'capi_enabled' => filter_var(env('META_CAPI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'access_token' => env('META_CAPI_TOKEN'),
    'test_event_code' => env('META_TEST_EVENT_CODE'), // optional, for Events Manager testing
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
        'brand' => env('META_DEFAULT_BRAND', env('APP_NAME', 'Store')),
        'google_product_category' => env('META_GOOGLE_CATEGORY'),
    ],
];
