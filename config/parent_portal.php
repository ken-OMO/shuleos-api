<?php

return [
    'pagination_max' => (int) env('PARENT_PORTAL_PAGINATION_MAX', 50),
    'dashboard_recent_payments' => (int) env('PARENT_PORTAL_DASHBOARD_RECENT_PAYMENTS', 5),
    'dashboard_upcoming_events' => (int) env('PARENT_PORTAL_DASHBOARD_UPCOMING_EVENTS', 10),
    'calendar_default_days' => (int) env('PARENT_PORTAL_CALENDAR_DEFAULT_DAYS', 30),
    'calendar_max_days' => (int) env('PARENT_PORTAL_CALENDAR_MAX_DAYS', 90),
    'device_limit' => (int) env('PARENT_PORTAL_DEVICE_LIMIT', 5),
    'document_mime_allowlist' => ['application/pdf'],
];
