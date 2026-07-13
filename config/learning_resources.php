<?php

return [
    'video_providers' => [
        'youtube.com' => ['allow_subdomains' => true],
        'youtu.be' => ['allow_subdomains' => false],
        'vimeo.com' => ['allow_subdomains' => true],
        'player.vimeo.com' => ['allow_subdomains' => false],
    ],
    'content_providers' => array_filter(array_map('trim', explode(',', (string) env('LEARNING_RESOURCE_CONTENT_PROVIDERS', '')))),
    'inactive_access_days' => 30,
];
