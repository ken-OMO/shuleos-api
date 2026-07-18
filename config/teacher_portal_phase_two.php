<?php

return [
    'sync_batch_limit' => 50,
    'sync_payload_bytes' => 262144,
    'correction_window_hours' => 48,
    'push_enabled' => env('TEACHER_PUSH_ENABLED', false),
    'push_provider' => env('TEACHER_PUSH_PROVIDER', 'log'),
    'push_retry_limit' => 3,
    'upload_scan_trusted' => env('TEACHER_UPLOAD_SCAN_TRUSTED', false),
    'quarantine_retention_days' => 30,
];
