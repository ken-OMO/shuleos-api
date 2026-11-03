<?php

return [
    'scheduler_enabled' => (bool) env('BOARDING_REPORTING_SCHEDULER_ENABLED', false),

    // Explicit deployment configuration: JSON object mapping school UUID to user UUID.
    // No actor is selected automatically and no permissions are granted here.
    'scheduler_actors' => json_decode(
        (string) env('BOARDING_REPORTING_SCHEDULER_ACTORS', '{}'),
        true
    ),
];
