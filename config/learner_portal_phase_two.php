<?php

return [
    'device_limit' => (int) env('LEARNER_DEVICE_LIMIT', 3),
    'push_enabled' => (bool) env('LEARNER_PUSH_ENABLED', false),
    'push_provider' => env('LEARNER_PUSH_PROVIDER', 'log'),
    'sync_batch_limit' => 40,
    'sync_pull_limit' => 100,
    'sync_payload_bytes' => 65536,
    'offline_resource_limit' => 50,
    'max_date_range_days' => 366,
    'help_request_daily_limit' => 10,
    'upload_scan_trusted' => false,
];
