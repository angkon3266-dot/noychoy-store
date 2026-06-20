<?php

return [
    'name' => env('APP_NAME', 'Noychoy'),
    'currency' => env('STORE_CURRENCY', 'BDT'),
    'currency_symbol' => env('STORE_CURRENCY_SYMBOL', '৳'),
    'phone' => env('STORE_PHONE'),
    'email' => env('STORE_EMAIL', 'hello@noychoy.com'),

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
