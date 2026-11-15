<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchoolBusinessTestDatabase
{
    public static function assertIsolated(bool $committedFixtures = false): void
    {
        if (! app()->environment('testing') || app()->configurationIsCached()
            || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Uncached PostgreSQL testing configuration required.');
        }

        // Reject URL overrides before resolving a connection or running any query.
        self::assertParameters(config('database.connections.pgsql', []));
        $connection = DB::connection('pgsql');
        self::assertParameters($connection->getConfig());
        $actual = $connection->selectOne('SELECT current_database() AS name');
        if ($actual->name !== 'shuleos_assessment_fk_test') {
            throw new RuntimeException('Unexpected actual test database.');
        }
        if ($committedFixtures && $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Concurrency fixtures require no outer transaction.');
        }
    }

    private static function assertParameters(array $connection): void
    {
        if (($connection['driver'] ?? null) !== 'pgsql'
            || ! empty($connection['url'])
            || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('An explicit local isolated PostgreSQL test database is required.');
        }
    }
}
