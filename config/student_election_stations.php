<?php

use App\Services\StudentElection\StudentElectionNetworkConfiguration;

return [
    // Prepared backend only; enabling production requires the remaining release review.
    'enabled' => env('STUDENT_ELECTION_STATIONS_ENABLED', false),
    'origin' => env('STUDENT_ELECTION_STATION_ORIGIN', env('APP_URL', 'http://localhost')),
    // Explicit school UUID => trusted IPv4/IPv6 CIDR list; no wildcard default.
    'school_networks' => StudentElectionNetworkConfiguration::parse(
        env('STUDENT_ELECTION_SCHOOL_NETWORKS', '')
    ),
];
