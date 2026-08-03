<?php

return [
    'maximum_recipients' => (int) env('COMMUNICATION_MAXIMUM_RECIPIENTS', 5000),
    'draft_retention_days' => (int) env('COMMUNICATION_DRAFT_RETENTION_DAYS', 30),
    'delivery_retention_days' => (int) env('COMMUNICATION_DELIVERY_RETENTION_DAYS', 365),
    'channels' => ['in_app', 'email', 'sms'],
    'frontend_url' => env('SHULEOS_FRONTEND_URL', 'https://shuleos.co.ke'),
    'email' => [
        'provider' => env('COMMUNICATION_EMAIL_PROVIDER', env('APP_ENV') === 'production' ? 'resend' : 'log'),
        'from_address' => env('COMMUNICATION_FROM_ADDRESS', 'noreply@shuleos.co.ke'),
        'from_name' => env('COMMUNICATION_FROM_NAME', 'ShuleOS'),
        'timeout' => (int) env('COMMUNICATION_EMAIL_TIMEOUT', 10),
        'retry_limit' => (int) env('COMMUNICATION_EMAIL_RETRY_LIMIT', 3),
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
            'base_url' => env('RESEND_API_BASE_URL', 'https://api.resend.com'),
            'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        ],
    ],
    'sms' => [
        'provider' => env('COMMUNICATION_SMS_PROVIDER', env('APP_ENV') === 'production' ? 'africas_talking' : 'fake'),
        'enabled' => (bool) env('COMMUNICATION_SMS_ENABLED', false),
        'maximum_segments' => (int) env('COMMUNICATION_SMS_MAXIMUM_SEGMENTS', 4),
        'allowed_categories' => ['fee_invoice', 'fee_payment_confirmation', 'fee_reminder', 'urgent_announcement', 'emergency', 'critical_attendance'],
        'africas_talking' => [
            'username' => env('AFRICASTALKING_USERNAME'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'sender_id' => env('AFRICASTALKING_SENDER_ID'),
            'environment' => env('AFRICASTALKING_ENVIRONMENT', 'sandbox'),
            'base_url' => env('AFRICASTALKING_BASE_URL', 'https://api.sandbox.africastalking.com'),
            'webhook_secret' => env('AFRICASTALKING_WEBHOOK_SECRET'),
        ],
    ],
    'webhook_max_bytes' => (int) env('COMMUNICATION_WEBHOOK_MAX_BYTES', 65536),
    'soft_bounce_suppression_threshold' => (int) env('COMMUNICATION_SOFT_BOUNCE_THRESHOLD', 3),
    'recurrence_minimum_minutes' => (int) env('COMMUNICATION_RECURRENCE_MINIMUM_MINUTES', 60),
    'scheduler_batch_size' => (int) env('COMMUNICATION_SCHEDULER_BATCH_SIZE', 100),
    'emergency_enabled' => (bool) env('COMMUNICATION_EMERGENCY_ENABLED', true),
    'sandbox_smoke_enabled' => (bool) env('COMMUNICATION_SANDBOX_SMOKE_ENABLED', false),
];
