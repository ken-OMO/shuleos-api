<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TransportFoundationDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Transport test database required.');
        }
    }

    public function test_day_and_mixed_schools_can_hold_transport_resources(): void
    {
        foreach (['day', 'day_and_boarding'] as $mode) {
            $fixture = $this->fixture($mode);

            $this->assertDatabaseHas('transport_vehicles', [
                'id' => $fixture['vehicle'],
                'school_id' => $fixture['school']->id,
                'learner_capacity' => 20,
            ]);

            foreach (['to_school', 'from_school'] as $direction) {
                $id = $this->stop($fixture, ['direction' => $direction]);
                $this->assertDatabaseHas('transport_route_stops', [
                    'id' => $id,
                    'direction' => $direction,
                ]);
            }

            $this->assertDatabaseHas('schools', [
                'id' => $fixture['school']->id,
                'operating_mode' => $mode,
            ]);
        }
    }

    public function test_foreign_actors_and_routes_are_rejected_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            foreach (['created_by', 'updated_by'] as $field) {
                $this->rejected(
                    fn () => $this->route($own, [
                        'created_by' => $foreign['user']->id,
                        'updated_by' => $foreign['user']->id,
                    ]),
                    '23503'
                );

                $this->rejected(
                    fn () => DB::table('transport_vehicles')
                        ->where('id', $own['vehicle'])
                        ->update([$field => $foreign['user']->id]),
                    $field === 'created_by' ? '23514' : '23503'
                );
            }

            $this->rejected(
                fn () => $this->stop($own, ['route_id' => $foreign['route']]),
                '23503'
            );
        }
    }

    public function test_scoped_codes_and_active_stop_order_are_unique(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        $this->rejected(
            fn () => $this->route($a, ['route_code' => ' route-1 ']),
            '23505'
        );

        $this->rejected(
            fn () => $this->vehicle($a, ['registration_number' => ' bus-1 ']),
            '23505'
        );

        $stop = $this->stop($a);
        $this->rejected(fn () => $this->stop($a), '23505');

        $this->assertNotEmpty($this->stop($b));
        $this->assertNotEmpty($this->stop($a, ['direction' => 'from_school']));

        DB::table('transport_route_stops')->where('id', $stop)
            ->update(['active' => false]);

        $this->assertNotEmpty($this->stop($a));
        $this->assertDatabaseHas('transport_route_stops', [
            'id' => $stop,
            'active' => false,
        ]);
    }

    public function test_invalid_values_and_initial_versions_are_rejected(): void
    {
        $fixture = $this->fixture();

        foreach ([
            ['learner_capacity' => 0],
            ['learner_capacity' => -1],
            ['ownership_type' => 'unknown'],
            ['registration_number' => '   '],
            ['lock_version' => 2],
        ] as $override) {
            $this->rejected(fn () => $this->vehicle($fixture, $override), '23514');
        }

        foreach ([
            ['route_code' => '   '],
            ['route_name' => '   '],
        ] as $override) {
            $this->rejected(fn () => $this->route($fixture, $override), '23514');
        }

        foreach ([
            ['direction' => 'unknown'],
            ['stop_order' => 0],
            ['stop_name' => '   '],
        ] as $override) {
            $this->rejected(fn () => $this->stop($fixture, $override), '23514');
        }
    }

    public function test_identity_is_preserved_and_database_controls_versions(): void
    {
        $fixture = $this->fixture();
        $stop = $this->stop($fixture);
        $otherRoute = $this->route($fixture);

        foreach ([
            ['transport_vehicles', $fixture['vehicle']],
            ['transport_routes', $fixture['route']],
            ['transport_route_stops', $stop],
        ] as [$table, $id]) {
            foreach ([
                ['id' => (string) Str::uuid()],
                ['school_id' => (string) Str::uuid()],
                ['created_at' => '2000-01-01 00:00:00+00'],
            ] as $override) {
                $this->rejected(
                    fn () => DB::table($table)->where('id', $id)->update($override),
                    '23514'
                );
            }

            $this->rejected(
                fn () => DB::table($table)->where('id', $id)->delete(),
                '23514'
            );

            DB::table($table)->where('id', $id)
                ->update(['active' => false, 'lock_version' => 999]);

            $this->assertSame(
                2,
                (int) DB::table($table)->where('id', $id)->value('lock_version')
            );
        }

        foreach ([
            ['route_id' => $otherRoute],
            ['direction' => 'from_school'],
        ] as $override) {
            $this->rejected(
                fn () => DB::table('transport_route_stops')
                    ->where('id', $stop)->update($override),
                '23514'
            );
        }
    }

    public function test_populated_rollback_is_refused_even_for_retired_records(): void
    {
        $fixture = $this->fixture();
        DB::table('transport_vehicles')->where('id', $fixture['vehicle'])
            ->update(['active' => false]);

        $migration = require database_path(
            'migrations/2026_11_03_160000_create_transport_foundation.php'
        );

        try {
            $migration->down();
            $this->fail('Populated Transport rollback was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain transport foundation records',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('transport_vehicles', ['id' => $fixture['vehicle']]);
    }

    public function test_empty_schema_can_roll_back_and_remigrate(): void
    {
        foreach ([
            'transport_service_schedules',
            'transport_route_stops',
            'transport_routes',
            'transport_vehicles',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }

        DB::beginTransaction();

        try {
            $migration = require database_path(
                'migrations/2026_11_03_160000_create_transport_foundation.php'
            );
            $scheduleMigration = require database_path(
                'migrations/2026_11_03_180000_create_transport_service_schedules.php'
            );

            // Roll back dependants before their referenced foundation.
            $assignmentMigration = require database_path(
                'migrations/2026_11_06_190000_create_transport_dated_assignments.php'
            );
            $reservationMigration = require database_path(
                'migrations/2026_11_06_200000_create_transport_learner_reservations.php'
            );
            $enrolmentMigration = require database_path(
                'migrations/2026_11_06_210000_create_transport_recurring_enrolments.php'
            );
            $tripMigration = require database_path(
                'migrations/2026_11_06_220000_create_transport_trip_rosters.php'
            );
            $handoverMigration = require database_path(
                'migrations/2026_11_06_230000_create_transport_handover_authorizations.php'
            );
            $journeyMigration = require database_path(
                'migrations/2026_11_06_235000_create_transport_journey_events.php'
            );
            $exceptionMigration = require database_path(
                'migrations/2026_11_06_235500_create_transport_exception_follow_ups.php'
            );
            $attendanceMigration = require database_path(
                'migrations/2026_11_07_001000_create_transport_attendance_and_completion.php'
            );
            $reportingMigration = require database_path(
                'migrations/2026_11_07_010000_create_transport_reporting_obligations.php'
            );
            $dailyMigration = require database_path(
                'migrations/2026_11_07_020000_create_transport_daily_reporting_evidence.php'
            );
            $dailyMigration->down();
            $reportingMigration->down();
            $attendanceMigration->down();
            $exceptionMigration->down();
            $journeyMigration->down();
            $handoverMigration->down();
            $tripMigration->down();
            $enrolmentMigration->down();
            $reservationMigration->down();
            $assignmentMigration->down();
            $scheduleMigration->down();
            $migration->down();

            $this->assertTrue(DB::getSchemaBuilder()->hasTable('transport_routes'));
            $this->assertFalse(
                DB::getSchemaBuilder()->hasColumn('transport_routes', 'route_code')
            );
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable('transport_service_schedules')
            );

            // Recreate referenced resources before their dependants.
            $migration->up();
            $scheduleMigration->up();
            $assignmentMigration->up();
            $reservationMigration->up();
            $enrolmentMigration->up();
            $tripMigration->up();
            $handoverMigration->up();
            $journeyMigration->up();
            $exceptionMigration->up();
            $attendanceMigration->up();
            $reportingMigration->up();
            $dailyMigration->up();

            $fixture = $this->fixture();
            $this->assertNotEmpty($this->stop($fixture));
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable('transport_service_schedules')
            );
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('transport_routes'));
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('transport_service_schedules')
        );
    }

    private function fixture(string $mode = 'day'): array
    {
        $school = SchoolBuilder::create(['operating_mode' => $mode]);
        $user = UserBuilder::create($school, RoleBuilder::create());
        $fixture = compact('school', 'user');
        $fixture['vehicle'] = $this->vehicle($fixture, ['registration_number' => 'BUS-1']);
        $fixture['route'] = $this->route($fixture, ['route_code' => 'ROUTE-1']);

        return $fixture;
    }

    private function base(array $fixture): array
    {
        return [
            'id' => (string) Str::uuid(),
            'school_id' => $fixture['school']->id,
            'created_by' => $fixture['user']->id,
            'updated_by' => $fixture['user']->id,
        ];
    }

    private function vehicle(array $fixture, array $override = []): string
    {
        $row = array_merge($this->base($fixture), [
            'registration_number' => 'BUS-'.Str::upper(Str::random(10)),
            'ownership_type' => 'school_owned',
            'learner_capacity' => 20,
        ], $override);
        DB::table('transport_vehicles')->insert($row);

        return $row['id'];
    }

    private function route(array $fixture, array $override = []): string
    {
        $row = array_merge($this->base($fixture), [
            'route_code' => 'R-'.Str::upper(Str::random(10)),
            'route_name' => 'Test route',
        ], $override);
        DB::table('transport_routes')->insert($row);

        return $row['id'];
    }

    private function stop(array $fixture, array $override = []): string
    {
        $row = array_merge($this->base($fixture), [
            'route_id' => $fixture['route'],
            'direction' => 'to_school',
            'stop_name' => 'Test stop',
            'stop_order' => 1,
        ], $override);
        DB::table('transport_route_stops')->insert($row);

        return $row['id'];
    }

    private function rejected(callable $operation, string $sqlState): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('Invalid Transport write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
    }
}
