<?php

$religiousEducation = [
    ['Christian Religious Education', 'CRE'],
    ['Islamic Religious Education', 'IRE'],
    ['Hindu Religious Education', 'HRE'],
];

return [
    'Early Years Education' => [
        ['Language Activities'],
        ['Mathematical Activities'],
        ['Environmental Activities'],
        ['Creative Activities', 'Creative and Psychomotor Activities'],
        ...$religiousEducation,
        ['Religious Activities'],
    ],
    'Lower Primary' => [
        ['English Activities', 'English'],
        ['Kiswahili'],
        ['Mathematics Activities', 'Mathematical Activities', 'Mathematics'],
        ['Environmental Activities'],
        ['Creative Activities', 'Creative Arts'],
        ...$religiousEducation,
        ['Religious Activities'],
    ],
    'Upper Primary' => [
        ['English'],
        ['Kiswahili'],
        ['Mathematics'],
        ['Science and Technology'],
        ['Agriculture'],
        ['Social Studies'],
        ['Creative Arts'],
        ...$religiousEducation,
    ],
    'Junior School' => [
        ['English'],
        ['Kiswahili'],
        ['Mathematics'],
        ['Integrated Science'],
        ['Agriculture'],
        ['Social Studies'],
        ['Pre-Technical Studies'],
        ...$religiousEducation,
    ],
];
