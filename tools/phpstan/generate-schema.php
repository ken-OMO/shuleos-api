<?php

declare(strict_types=1);
use Larastan\Larastan\SQL\IamcalSqlParser;
use Larastan\Larastan\SQL\SqlParserFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$sql = file_get_contents($root.'/database/schema/pgsql-schema.sql');
if ($sql === false) {
    throw new RuntimeException('Cannot read PostgreSQL schema.');
}

preg_match_all(
    '/CREATE TABLE public\.(\w+) \(\r?\n([\s\S]*?)\r?\n\);/',
    $sql,
    $tables,
    PREG_SET_ORDER
);

if ($tables === []) {
    throw new RuntimeException('No PostgreSQL tables discovered.');
}

$output = "-- Generated for static analysis only. Never execute against a database.\n";
$expected = [];

foreach ($tables as $table) {
    $columns = [];
    foreach (explode("\n", $table[2]) as $line) {
        if (! preg_match('/^    (\w+) (.+)/', $line, $column)) {
            continue;
        }
        if (in_array($column[1], ['CONSTRAINT', 'CHECK', 'PRIMARY', 'UNIQUE', 'FOREIGN'], true)) {
            continue;
        }

        $definition = rtrim(trim($column[2]), ',');
        $type = preg_split('/ DEFAULT | CONSTRAINT | NOT NULL/', $definition)[0];
        $mapped = match (true) {
            $type === 'uuid' => 'CHAR(36)',
            $type === 'boolean' => 'BOOLEAN',
            $type === 'jsonb' => 'JSON',
            $type === 'text' => 'TEXT',
            $type === 'date' => 'DATE',
            $type === 'time without time zone' => 'TIME',
            preg_match('/^timestamp(?:\(\d+\))? without time zone$/', $type) === 1 => 'DATETIME',
            preg_match('/^character varying\((\d+)\)$/', $type, $length) === 1 => 'VARCHAR('.$length[1].')',
            preg_match('/^numeric\((\d+),(\d+)\)$/', $type, $precision) === 1 => 'DECIMAL('.$precision[1].','.$precision[2].')',
            in_array($type, ['smallint', 'integer', 'bigint'], true) => strtoupper($type),
            default => throw new RuntimeException('Unsupported analysis type: '.$type),
        };

        $nullable = ! str_contains($definition, 'NOT NULL');
        $columns[] = '    `'.$column[1].'` '.$mapped.($nullable ? ' NULL' : ' NOT NULL');
        $expected[$table[1]][] = $column[1];
    }

    if ($columns === []) {
        throw new RuntimeException('No columns discovered for '.$table[1]);
    }

    $output .= 'CREATE TABLE `'.$table[1]."` (\n".implode(",\n", $columns)."\n);\n\n";
}

// Verify that Larastan discovers every projected table and column.
$parser = (new SqlParserFactory(
    new IamcalSqlParser
))->create();

$actual = [];
foreach ($parser->parseTables($output) as $table) {
    $actual[$table->name] = array_map(
        fn ($column) => $column->name,
        $table->columns
    );
}

if ($actual !== $expected) {
    throw new RuntimeException('Larastan schema projection verification failed.');
}

$output = rtrim($output, "\n")."\n";

$target = $root.'/tools/phpstan/schema/analysis.sql';
if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}
if (file_put_contents($target, $output) === false) {
    throw new RuntimeException('Cannot write analysis schema.');
}

echo 'ANALYSIS_SCHEMA_TABLES=', count($actual), PHP_EOL;
echo 'ANALYSIS_SCHEMA_VERIFIED=YES', PHP_EOL;
