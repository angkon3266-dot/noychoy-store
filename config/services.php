<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google OAuth (customer "Continue with Google"). Set these in .env.
    // Redirect URI must match exactly in Google Cloud Console, e.g.
    // https://nocyhoy.com/auth/google/callback
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // The storefront chat assistant. Every value here is overridden at runtime
    // by Admin → System Config → AI assistant (the key is stored encrypted
    // there), so .env is only the fallback for a fresh install.
    'openai' => [
        'assistant_enabled' => env('AI_ASSISTANT_ENABLED', false),
        // Whether the assistant may take an order end to end (find the piece,
        // ask the checkout questions, confirm, place it). Cash on delivery
        // means a real parcel and real courier cost, so the owner can switch
        // this off without switching off the assistant.
        'orders_enabled' => env('AI_ASSISTANT_ORDERS', true),
        // Ceiling on orders one visitor may place through chat in a day, and
        // the biggest order value the assistant may place unaided. Anything
        // above the ceiling is handed to a person instead.
        'orders_per_day' => (int) env('AI_ASSISTANT_ORDERS_PER_DAY', 3),
        'orders_max_total' => (float) env('AI_ASSISTANT_ORDERS_MAX_TOTAL', 20000),
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'greeting' => null,
        'instructions' => null,
        'timeout' => 45,
    ],

];
