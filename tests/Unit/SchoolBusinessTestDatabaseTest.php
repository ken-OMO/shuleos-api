<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SchoolBusinessTestDatabase;
use Tests\TestCase;

class SchoolBusinessTestDatabaseTest extends TestCase
{
    private array $parameters = [
        'driver' => 'pgsql', 'url' => null,
        'host' => '127.0.0.1', 'database' => 'shuleos_assessment_fk_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => $this->parameters]);
    }

    public static function unsafeConfiguration(): array
    {
        return [
            'URL overrides name and host' => [['url' => 'pgsql://db.example.invalid/shuleos']],
            'even a test URL is disallowed' => [['url' => 'pgsql://localhost/shuleos_assessment_fk_test']],
            'production database name' => [['database' => 'shuleos']],
            'remote host' => [['host' => 'db.example.invalid']],
            'wrong driver' => [['driver' => 'mysql']],
        ];
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_raw_configuration_is_rejected_before_resolving_a_connection(array $overrides): void
    {
        config(['database.connections.pgsql' => array_merge($this->parameters, $overrides)]);
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        SchoolBusinessTestDatabase::assertIsolated();
    }

    public function test_wrong_default_connection_is_rejected_before_resolving_a_connection(): void
    {
        config(['database.default' => 'mysql']);
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        SchoolBusinessTestDatabase::assertIsolated();
    }

    public function test_resolved_connection_mismatch_is_rejected_before_any_sql(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->once()->andReturn(array_merge($this->parameters, ['database' => 'shuleos']));
        $connection->shouldNotReceive('selectOne');
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        $this->expectException(RuntimeException::class);
        SchoolBusinessTestDatabase::assertIsolated();
    }

    public function test_actual_database_identity_is_checked_before_allowing_fixtures(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->once()->andReturn($this->parameters);
        $connection->shouldReceive('selectOne')->once()->with('SELECT current_database() AS name')
            ->andReturn((object) ['name' => 'shuleos']);
        $connection->shouldNotReceive('transactionLevel');
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        $this->expectException(RuntimeException::class);
        SchoolBusinessTestDatabase::assertIsolated(true);
    }

    public function test_concurrency_fixtures_reject_an_outer_transaction(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->once()->andReturn($this->parameters);
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['name' => 'shuleos_assessment_fk_test']);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        $this->expectException(RuntimeException::class);
        SchoolBusinessTestDatabase::assertIsolated(true);
    }
}
