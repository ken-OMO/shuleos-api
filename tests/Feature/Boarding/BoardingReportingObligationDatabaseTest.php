<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingReportingObligationDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::selectOne('SELECT current_database() AS name')->name;

        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! in_array($database, ['shuleos_assessment_fk_test', 'shuleos_testing'], true)) {
            throw new \RuntimeException('An isolated PostgreSQL test database is required.');
        }
    }

    public function test_policy_derives_next_day_deadline_in_school_timezone(): void
    {
        $fixture = $this->fixture();
        $id = $this->obligation($fixture);

        $row = DB::table('boarding_daily_obligations')->where('id', $id)->first();
        $this->assertSame('Africa/Nairobi', $row->timezone);

        $instant = DB::selectOne(
            "SELECT due_at AT TIME ZONE 'UTC' AS utc
             FROM boarding_daily_obligations WHERE id = ?",
            [$id]
        );

        // Nov 3 at 08:00 Nairobi, plus 30-minute grace, is 05:30 UTC.
        $this->assertSame('2026-11-03 05:30:00', $instant->utc);
    }

    public function test_foreign_hostel_policy_and_actor_are_rejected_both_ways(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            foreach ([
                ['hostel_id' => $foreign['hostel']],
                ['policy_id' => $foreign['policy']],
                ['created_by' => $foreign['user']->id],
            ] as $override) {
                $this->rejected(
                    fn () => $this->obligation($own, $override),
                    '23503'
                );
            }

            $this->rejected(
                fn () => $this->policy($own, [
                    'revision' => 2,
                    'effective_from' => '2026-11-03',
                    'created_by' => $foreign['user']->id,
                ]),
                '23503'
            );

            $this->assertNotEmpty($this->obligation($own));
        }
    }

    public function test_duplicate_obligation_and_policy_scope_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->obligation($fixture);

        $this->rejected(fn () => $this->obligation($fixture), '23505');
        $this->rejected(fn () => $this->policy($fixture), '23505');
    }

    public function test_invalid_policy_values_and_forged_deadlines_are_rejected(): void
    {
        $fixture = $this->fixture();

        foreach ([
            ['revision' => 0],
            ['timezone' => 'Unknown/Timezone'],
            ['deadline_time' => '25:00'],
            ['deadline_day_offset' => 2],
            ['grace_minutes' => -1],
            ['weekday_mask' => 0],
            ['weekday_mask' => 128],
        ] as $override) {
            $this->rejected(fn () => $this->policy($fixture, $override + [
                'revision' => 2,
                'effective_from' => '2026-11-03',
            ]), '23514');
        }

        foreach ([
            ['timezone' => 'UTC'],
            ['due_at' => '2026-11-02 00:00:00+00'],
            ['reporting_date' => '2026-10-31'],
        ] as $override) {
            $this->rejected(fn () => $this->obligation($fixture, $override), '23514');
        }
    }

    public function test_disabled_weekday_and_superseded_policies_cannot_create_obligations(): void
    {
        $fixture = $this->fixture();

        // November 3, 2026 is Tuesday; mask 1 permits Monday only.
        $mondayPolicy = $this->policy($fixture, [
            'revision' => 2,
            'effective_from' => '2026-11-03',
            'weekday_mask' => 1,
        ]);

        $this->rejected(fn () => $this->obligation($fixture, [
            'policy_id' => $mondayPolicy,
            'reporting_date' => '2026-11-03',
        ]), '23514');

        $this->rejected(fn () => $this->obligation($fixture, [
            'reporting_date' => '2026-11-03',
        ]), '23514');

        $disabled = $this->policy($fixture, [
            'revision' => 3,
            'effective_from' => '2026-11-04',
            'enabled' => false,
        ]);

        $this->rejected(fn () => $this->obligation($fixture, [
            'policy_id' => $disabled,
            'reporting_date' => '2026-11-04',
        ]), '23514');

        $this->assertNotEmpty($this->obligation($fixture));
    }

    public function test_existing_evidence_survives_later_policy_and_rejects_mutation(): void
    {
        $fixture = $this->fixture();
        $id = $this->obligation($fixture);
        $before = (array) DB::table('boarding_daily_obligations')->where('id', $id)->first();

        $this->policy($fixture, [
            'revision' => 2,
            'effective_from' => '2026-11-03',
            'deadline_time' => '10:00',
            'timezone' => 'UTC',
        ]);

        foreach ([
            ['boarding_reporting_policies', $fixture['policy']],
            ['boarding_daily_obligations', $id],
        ] as [$table, $record]) {
            $this->rejected(
                fn () => DB::table($table)->where('id', $record)
                    ->update(['created_at' => now()->addDay()]),
                '23514'
            );
            $this->rejected(
                fn () => DB::table($table)->where('id', $record)->delete(),
                '23514'
            );
        }

        $this->assertSame(
            $before,
            (array) DB::table('boarding_daily_obligations')->where('id', $id)->first()
        );

        $migration = require database_path(
            'migrations/2026_11_02_110000_create_boarding_reporting_obligations.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback discarded reporting evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain boarding reporting evidence',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            $before,
            (array) DB::table('boarding_daily_obligations')->where('id', $id)->first()
        );
    }

    public function test_empty_schema_rollback_and_remigration_work(): void
    {
        $this->assertSame(0, DB::table('boarding_daily_obligations')->count());
        $this->assertSame(0, DB::table('boarding_reporting_policies')->count());

        DB::beginTransaction();

        try {
            $migration = require database_path(
                'migrations/2026_11_02_110000_create_boarding_reporting_obligations.php'
            );

            $reportsMigration = require database_path(
                'migrations/2026_11_02_120000_create_boarding_daily_report_evidence.php'
            );
            $occurrencesMigration = require database_path(
                'migrations/2026_11_02_130000_add_boarding_report_occurrences.php'
            );
            $occurrencesMigration->down();
            $reportsMigration->down();
            $migration->down();
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable('boarding_daily_obligations')
            );

            $migration->up();
            $reportsMigration->up();
            $occurrencesMigration->up();
            $fixture = $this->fixture();
            $this->assertNotEmpty($this->obligation($fixture));
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('boarding_daily_obligations')
        );
    }

    private function fixture(): array
    {
        $school = SchoolBuilder::create();
        $user = UserBuilder::create($school, RoleBuilder::create());
        $hostel = (string) Str::uuid();

        DB::table('hostels')->insert([
            'id' => $hostel,
            'school_id' => $school->id,
            'hostel_name' => 'Reporting '.Str::random(10),
            'hostel_type' => 'BOYS',
            'capacity' => 20,
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
        ]);

        $fixture = compact('school', 'user', 'hostel');
        $fixture['policy'] = $this->policy($fixture);

        return $fixture;
    }

    private function policy(array $fixture, array $override = []): string
    {
        $id = (string) Str::uuid();

        DB::table('boarding_reporting_policies')->insert(array_merge([
            'id' => $id,
            'school_id' => $fixture['school']->id,
            'revision' => 1,
            'enabled' => true,
            'effective_from' => '2026-11-01',
            'timezone' => 'Africa/Nairobi',
            'deadline_time' => '08:00',
            'deadline_day_offset' => 1,
            'grace_minutes' => 30,
            'weekday_mask' => 127,
            'created_by' => $fixture['user']->id,
        ], $override));

        return $id;
    }

    private function obligation(array $fixture, array $override = []): string
    {
        $id = (string) Str::uuid();

        DB::table('boarding_daily_obligations')->insert(array_merge([
            'id' => $id,
            'school_id' => $fixture['school']->id,
            'hostel_id' => $fixture['hostel'],
            'policy_id' => $fixture['policy'],
            'reporting_date' => '2026-11-02',
            'created_by' => $fixture['user']->id,
        ], $override));

        return $id;
    }

    private function rejected(callable $operation, string $sqlState): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('Invalid boarding reporting write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
    }
}
