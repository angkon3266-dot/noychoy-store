<?php

return [
    'enabled' => filter_var(env('SMS_ENABLED', false), FILTER_VALIDATE_BOOL),

    // KhudeBarta HTTP API v5.3.0
    'base_url' => rtrim((string) env('KHUDEBARTA_BASE_URL', ''), '/'),
    'api_key' => env('KHUDEBARTA_API_KEY'),
    'secret_key' => env('KHUDEBARTA_SECRET_KEY'),
    'caller_id' => env('KHUDEBARTA_CALLER_ID'),
    'timeout' => 20,

    // Editable order-lifecycle templates. {placeholders} replaced at send time.
    'templates' => [
        'order_placed' => 'Dear {name}, your Noychoy order {order} ({qty} item/s, Tk {total}) is received. We will call to confirm. Thank you!',
        'order_confirmed' => 'Dear {name}, your Noychoy order {order} is confirmed and being prepared for delivery.',
        'order_shipped' => 'Dear {name}, your Noychoy order {order} has been shipped via Steadfast. Tracking: {tracking}.',
        'order_delivered' => 'Dear {name}, your Noychoy order {order} has been delivered. Thank you for shopping with us!',
        'order_cancelled' => 'Dear {name}, your Noychoy order {order} has been cancelled. Contact us for any questions.',
        'password_reset' => 'Your {store} password reset code is {code}. Valid for {minutes} minutes.',
    ],
];
