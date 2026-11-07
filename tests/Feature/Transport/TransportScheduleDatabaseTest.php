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

class TransportScheduleDatabaseTest extends TestCase
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

    public function test_same_day_and_explicit_overnight_windows_are_supported(): void
    {
        $f = $this->fixture();

        foreach ([
            [],
            [
                'direction' => 'from_school',
                'departure_time' => '23:00',
                'completion_time' => '01:00',
                'completion_day_offset' => 1,
            ],
        ] as $override) {
            $id = $this->schedule($f, $override);
            $this->assertDatabaseHas('transport_service_schedules', [
                'id' => $id,
                'school_id' => $f['school']->id,
                'timezone' => 'Africa/Nairobi',
            ]);
        }
    }

    public function test_invalid_dates_weekdays_timezones_and_windows_are_rejected(): void
    {
        $f = $this->fixture();

        foreach ([
            ['weekday_mask' => 0],
            ['weekday_mask' => 128],
            ['effective_to' => '2026-10-31'],
            ['effective_from' => 'infinity'],
            ['timezone' => 'Invalid/Timezone'],
            ['direction' => 'unknown'],
            ['completion_time' => '06:00'],
            ['completion_time' => '07:00'],
            ['completion_day_offset' => 2],
            ['completion_day_offset' => 1, 'completion_time' => '08:00'],
            ['departure_time' => '24:00'],
            ['turnaround_minutes' => -1],
            ['service_code' => '   '],
            ['lock_version' => 2],
        ] as $override) {
            $this->rejected(fn () => $this->schedule($f, $override), '23514');
        }
    }

    public function test_foreign_routes_and_actors_are_rejected_both_ways(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->rejected(
                fn () => $this->schedule($own, ['route_id' => $foreign['route']]),
                '23503'
            );
            $this->rejected(
                fn () => $this->schedule($own, [
                    'created_by' => $foreign['user']->id,
                    'updated_by' => $foreign['user']->id,
                ]),
                '23503'
            );
        }
    }

    public function test_codes_are_scoped_and_retired_codes_remain_reserved(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        $id = $this->schedule($a, ['service_code' => 'AM-1']);
        $this->assertNotEmpty($this->schedule($b, ['service_code' => 'AM-1']));

        DB::table('transport_service_schedules')->where('id', $id)
            ->update(['active' => false]);

        $this->rejected(
            fn () => $this->schedule($a, ['service_code' => ' am-1 ']),
            '23505'
        );
    }

    public function test_schedule_definition_is_preserved_and_version_is_database_managed(): void
    {
        $f = $this->fixture();
        $id = $this->schedule($f);

        foreach ([
            ['direction' => 'from_school'],
            ['departure_time' => '07:15'],
            ['weekday_mask' => 127],
            ['timezone' => 'UTC'],
            ['effective_to' => '2026-12-31'],
            ['school_id' => (string) Str::uuid()],
        ] as $override) {
            $this->rejected(
                fn () => DB::table('transport_service_schedules')
                    ->where('id', $id)->update($override),
                '23514'
            );
        }

        $this->rejected(
            fn () => DB::table('transport_service_schedules')->where('id', $id)->delete(),
            '23514'
        );

        DB::table('transport_service_schedules')->where('id', $id)
            ->update(['active' => false, 'lock_version' => 999]);

        $this->assertSame(
            2,
            (int) DB::table('transport_service_schedules')
                ->where('id', $id)->value('lock_version')
        );
    }

    public function test_populated_rollback_is_refused_and_empty_schema_remigrates(): void
    {
        $migration = require database_path(
            'migrations/2026_11_03_180000_create_transport_service_schedules.php'
        );

        DB::beginTransaction();
        try {
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
            $migration->down();
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable('transport_service_schedules')
            );
            $migration->up();
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
            $id = $this->schedule($this->fixture());

            try {
                $migration->down();
                $this->fail('Populated schedule rollback was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'Retain transport schedule records',
                    $exception->getMessage()
                );
            }

            $this->assertDatabaseHas('transport_service_schedules', ['id' => $id]);
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('transport_service_schedules')
        );
    }

    private function fixture(): array
    {
        $school = SchoolBuilder::create(['operating_mode' => 'day']);
        $user = UserBuilder::create($school, RoleBuilder::create());
        $route = (string) Str::uuid();

        DB::table('transport_routes')->insert([
            'id' => $route,
            'school_id' => $school->id,
            'route_code' => 'R-'.Str::random(12),
            'route_name' => 'Schedule test route',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return compact('school', 'user', 'route');
    }

    private function schedule(array $f, array $override = []): string
    {
        $values = array_merge([
            'id' => (string) Str::uuid(),
            'school_id' => $f['school']->id,
            'route_id' => $f['route'],
            'service_code' => 'S-'.Str::random(12),
            'direction' => 'to_school',
            'timezone' => 'Africa/Nairobi',
            'weekday_mask' => 31,
            'effective_from' => '2026-11-01',
            'departure_time' => '07:00',
            'completion_time' => '08:00',
            'created_by' => $f['user']->id,
            'updated_by' => $f['user']->id,
        ], $override);

        DB::table('transport_service_schedules')->insert($values);

        return $values['id'];
    }

    private function rejected(callable $operation, string $state): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('Invalid schedule write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
    }
}
