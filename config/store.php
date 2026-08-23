<?php

return [
    'name' => env('APP_NAME', 'Store'),
    'currency' => env('STORE_CURRENCY', 'BDT'),
    'currency_symbol' => env('STORE_CURRENCY_SYMBOL', '৳'),
    'phone' => env('STORE_PHONE'),
    'email' => env('STORE_EMAIL'),

    // The shop's wall clock. Timestamps are STORED in UTC (config/app.php) —
    // that stays, because changing it would re-interpret every row already in
    // the database. This is for the two things that must reason in local time:
    // what day it is in Bangladesh, and what a customer is shown.
    //
    // It matters more than it looks. Dhaka is UTC+6, so between midnight and
    // 6am local the server still thinks it is yesterday — a quarter of the
    // clock, and a real slice of late-night mobile browsing here.
    'timezone' => env('STORE_TIMEZONE', 'Asia/Dhaka'),

    // Flat-rate COD shipping (BDT). Overridable per-order in admin.
    'shipping' => [
        'inside_dhaka' => (float) env('SHIPPING_INSIDE_DHAKA', 70),
        'outside_dhaka' => (float) env('SHIPPING_OUTSIDE_DHAKA', 130),
        // null = disabled. Subtotal at/above this ships free.
        'free_threshold' => env('FREE_SHIPPING_THRESHOLD') !== null && env('FREE_SHIPPING_THRESHOLD') !== ''
            ? (float) env('FREE_SHIPPING_THRESHOLD')
            : null,
    ],
];
