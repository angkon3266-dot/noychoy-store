<?php

return [
    // Meta (Facebook) Pixel + Conversions API.
    'pixel_id' => env('META_PIXEL_ID'),

    // Conversions API (server-side). Token kept in .env for security.
    'capi_enabled' => filter_var(env('META_CAPI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'access_token' => env('META_CAPI_TOKEN'),
    'test_event_code' => env('META_TEST_EVENT_CODE'), // optional, for Events Manager testing
    'graph_version' => 'v21.0',
];
