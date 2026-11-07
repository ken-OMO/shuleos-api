<?php

return [
    // Only an explicit boolean true enables either scheduled or command execution.
    'scheduler_enabled' => env('TRANSPORT_REPORTING_SCHEDULER_ENABLED', false) === true,

    // Deployment configuration: JSON object {"school UUID":"actor user UUID"}.
    // Empty by default. No actor is chosen and no permission is granted here.
    // Malformed mappings are rejected before writes whenever execution is enabled.
    'scheduler_actors' => json_decode(
        (string) env('TRANSPORT_REPORTING_SCHEDULER_ACTORS', '{}'),
        true
    ),
];
