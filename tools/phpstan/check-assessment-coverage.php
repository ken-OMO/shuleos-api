<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = file_get_contents($root.'/phpstan-assessment.neon');
if ($config === false) {
    throw new RuntimeException('Cannot read assessment configuration.');
}

if (preg_match('/baseline|^\s*(?:ignoreErrors|excludePaths)\s*:/mi', $config)) {
    throw new RuntimeException('Assessment analysis must not suppress or exclude errors.');
}

preg_match_all('/^        - ([^\r\n]+)$/m', $config, $matches);
$paths = array_map('trim', $matches[1]);
$expected = [
    'app/Services/Assessment',
    'app/Http/Requests/Assessment',
    'app/Services/Pdf/ReportCardPdfService.php',
];

foreach (['app/Models', 'app/Http/Controllers/Api', 'app/Http/Resources'] as $directory) {
    foreach (glob($root.'/'.$directory.'/*.php') ?: [] as $file) {
        if (preg_match('/^(Assessment|Exam|LearningAreaResult|MarkEntryPermission|MeritList|ReportCard)/', basename($file))) {
            $expected[] = $directory.'/'.basename($file);
        }
    }
}

foreach ($expected as $path) {
    if (! in_array($path, $paths, true)) {
        throw new RuntimeException('Missing assessment analysis path: '.$path);
    }
}

foreach ($paths as $path) {
    if (! file_exists($root.'/'.$path)) {
        throw new RuntimeException('Analysis path does not exist: '.$path);
    }
}

echo 'ASSESSMENT_COVERAGE_VERIFIED=YES', PHP_EOL;
