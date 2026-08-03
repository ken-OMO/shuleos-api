<?php

return [
    'device_limit' => (int) env('LEADERSHIP_DEVICE_LIMIT', 5),
    'dashboard_cache_seconds' => (int) env('LEADERSHIP_DASHBOARD_CACHE_SECONDS', 60),
    'max_date_range_days' => 366,
    'page_size' => 20,
    'max_report_rows' => 500,
    'chronic_absence_threshold' => 20,
    'low_attendance_threshold' => 85,
    'low_sms_wallet_threshold' => 100,
];
