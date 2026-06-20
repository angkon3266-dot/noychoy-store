<?php

return [
    // Used only by the one-time migration importer (php artisan woo:import).
    'store_url' => rtrim((string) env('WC_STORE_URL', ''), '/'),
    'consumer_key' => env('WC_CONSUMER_KEY'),
    'consumer_secret' => env('WC_CONSUMER_SECRET'),
    'timeout' => 60,
    'per_page' => 50,
];
