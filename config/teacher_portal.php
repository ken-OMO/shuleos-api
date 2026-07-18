<?php

return [
    'pagination_max' => (int) env('TEACHER_PORTAL_PAGINATION_MAX', 50),
    'dashboard_limit' => (int) env('TEACHER_PORTAL_DASHBOARD_LIMIT', 10),
    'calendar_default_days' => (int) env('TEACHER_PORTAL_CALENDAR_DEFAULT_DAYS', 30),
    'calendar_max_days' => (int) env('TEACHER_PORTAL_CALENDAR_MAX_DAYS', 90),
    'device_limit' => (int) env('TEACHER_PORTAL_DEVICE_LIMIT', 5),
    'batch_limit' => (int) env('TEACHER_PORTAL_BATCH_LIMIT', 100),
];
