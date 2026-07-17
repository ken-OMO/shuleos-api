<?php

return [
    'maximum_recipients' => (int) env('COMMUNICATION_MAXIMUM_RECIPIENTS', 5000),
    'draft_retention_days' => (int) env('COMMUNICATION_DRAFT_RETENTION_DAYS', 30),
    'delivery_retention_days' => (int) env('COMMUNICATION_DELIVERY_RETENTION_DAYS', 365),
    'channels' => ['in_app', 'email'],
];
