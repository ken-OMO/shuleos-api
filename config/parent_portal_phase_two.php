<?php

return [
    'payment_provider' => env('PARENT_PAYMENT_PROVIDER', env('APP_ENV') === 'production' ? 'disabled' : 'log'),
    'minimum_payment_minor' => (int) env('PARENT_PAYMENT_MINIMUM_MINOR', 100),
    'maximum_payment_minor' => (int) env('PARENT_PAYMENT_MAXIMUM_MINOR', 100000000),
    'callback_secret' => env('MPESA_CALLBACK_SECRET'),
    'callback_max_bytes' => (int) env('PARENT_PAYMENT_CALLBACK_MAX_BYTES', 32768),
    'attempt_expiry_minutes' => (int) env('PARENT_PAYMENT_ATTEMPT_EXPIRY_MINUTES', 30),
    'push_enabled' => (bool) env('PARENT_PUSH_ENABLED', false),
    'mpesa' => [
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'transaction_type' => env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
        'base_url' => env('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke'),
        'timeout' => (int) env('MPESA_TIMEOUT', 10),
        'retry_limit' => (int) env('MPESA_RETRY_LIMIT', 1),
    ],
];
