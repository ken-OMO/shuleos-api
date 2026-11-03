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

class BoardingReportOccurrenceDatabaseTest extends TestCase
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

    public function test_occurrences_and_corrections_are_preserved_in_each_submission(): void
    {
        $f = $this->fixture();
        $f['category'] = $this->category($f);
        $event = $this->occurrence($f);
        $logical = DB::table('boarding_report_occurrence_events')
            ->where('id', $event)->value('event_id');

        $submission = $this->submit($f);
        $original = DB::table('boarding_daily_report_submissions')
            ->where('id', $submission)->value('snapshot');
        $payload = json_decode($original, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['schema_version']);
        $this->assertCount(1, $payload['occurrences']);
        $this->assertSame('Water supply interruption', $payload['occurrences'][0]['description']);

        $this->review($f, $submission, 'changes_requested');
        $revision = $this->draft($f, 2);

        $this->occurrence($f, [
            'revision_id' => $revision,
            'event_id' => $logical,
            'version' => 2,
            'previous_id' => $event,
            'description' => 'Water restored after maintenance',
            'reason' => 'Correct the recorded outcome',
        ]);

        $corrected = $this->submit($f, $revision);
        $payload = json_decode(
            DB::table('boarding_daily_report_submissions')
                ->where('id', $corrected)->value('snapshot'),
            true, 512, JSON_THROW_ON_ERROR
        );

        $this->assertSame(2, $payload['occurrences'][0]['version']);
        $this->assertSame(
            'Water restored after maintenance',
            $payload['occurrences'][0]['description']
        );

        DB::table('boarding_occurrence_categories')->where('id', $f['category'])
            ->update(['category_name' => 'Renamed later']);

        $this->assertSame(
            $original,
            DB::table('boarding_daily_report_submissions')
                ->where('id', $submission)->value('snapshot')
        );
        $this->assertDatabaseHas('boarding_report_occurrence_events', [
            'id' => $event,
            'description' => 'Water supply interruption',
        ]);
    }

    public function test_occurrence_tenant_boundaries_fail_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $a['category'] = $this->category($a);
        $b['category'] = $this->category($b);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            foreach ([
                ['category_id' => $foreign['category']],
                ['revision_id' => $foreign['revision']],
                ['recorded_by' => $foreign['user']->id, 'assignment_id' => $foreign['assignment']],
            ] as $override) {
                $this->rejected(fn () => $this->occurrence($own, $override), '23503');
            }

            $this->assertNotEmpty($this->occurrence($own));
        }
    }

    public function test_voiding_preserves_content_and_requires_explicit_no_occurrence_declaration(): void
    {
        $f = $this->fixture();
        $f['category'] = $this->category($f);
        $event = $this->occurrence($f);
        $logical = DB::table('boarding_report_occurrence_events')
            ->where('id', $event)->value('event_id');

        $void = [
            'event_id' => $logical,
            'version' => 2,
            'previous_id' => $event,
            'event_state' => 'voided',
            'reason' => 'Recorded against the wrong facility',
        ];

        $this->rejected(fn () => $this->occurrence($f, $void + [
            'description' => 'Rewritten while voiding',
        ]), '23514');

        $voidId = $this->occurrence($f, $void);
        $this->rejected(fn () => $this->submit($f), '23514');

        DB::table('boarding_daily_report_revisions')->where('id', $f['revision'])
            ->update([
                'lock_version' => DB::raw('lock_version + 1'),
                'no_occurrences' => true,
            ]);

        $submission = $this->submit($f);
        $payload = json_decode(
            DB::table('boarding_daily_report_submissions')
                ->where('id', $submission)->value('snapshot'),
            true, 512, JSON_THROW_ON_ERROR
        );

        $this->assertSame('voided', $payload['occurrences'][0]['event_state']);
        $this->assertDatabaseHas('boarding_report_occurrence_events', ['id' => $event]);
        $this->assertDatabaseHas('boarding_report_occurrence_events', ['id' => $voidId]);
    }

    public function test_occurrence_time_state_and_immutability_are_enforced(): void
    {
        $f = $this->fixture();
        $f['category'] = $this->category($f);

        $this->rejected(fn () => $this->occurrence($f, [
            'occurred_at' => '2026-11-01 12:00:00+03',
        ]), '23514');

        $this->rejected(fn () => $this->occurrence($f, [
            'occurred_at' => now()->addDay(),
        ]), '23514');

        $this->rejected(fn () => $this->occurrence($f, [
            'follow_up_required' => true,
            'follow_up_notes' => '',
        ]), '23514');

        $event = $this->occurrence($f);

        $this->rejected(fn () => DB::table('boarding_report_occurrence_events')
            ->where('id', $event)->update(['description' => 'Rewrite']), '23514');
        $this->rejected(fn () => DB::table('boarding_report_occurrence_events')
            ->where('id', $event)->delete(), '23514');

        $this->submit($f);
        $this->rejected(fn () => $this->occurrence($f), '23514');
    }

    public function test_populated_occurrence_rollback_is_refused(): void
    {
        $f = $this->fixture();
        $f['category'] = $this->category($f);
        $event = $this->occurrence($f);

        $migration = require database_path(
            'migrations/2026_11_02_130000_add_boarding_report_occurrences.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback discarded occurrence evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain boarding occurrence evidence',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('boarding_report_occurrence_events', ['id' => $event]);
    }

    public function test_empty_occurrence_schema_can_roll_back_and_remigrate(): void
    {
        DB::beginTransaction();

        try {
            $migration = require database_path(
                'migrations/2026_11_02_130000_add_boarding_report_occurrences.php'
            );
            $migration->down();
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable('boarding_report_occurrence_events')
            );
            $migration->up();

            $f = $this->fixture();
            $f['category'] = $this->category($f);
            $this->assertNotEmpty($this->occurrence($f));
        } finally {
            DB::rollBack();
        }
    }

    private function category(array $f): string
    {
        return $this->insert('boarding_occurrence_categories', [
            'school_id' => $f['school']->id,
            'category_name' => 'Facilities',
            'active' => true,
            'created_by' => $f['user']->id,
        ]);
    }

    private function occurrence(array $f, array $override = []): string
    {
        return $this->insert('boarding_report_occurrence_events', array_merge([
            'school_id' => $f['school']->id,
            'report_id' => $f['report'],
            'revision_id' => $f['revision'],
            'event_id' => (string) Str::uuid(),
            'version' => 1,
            'previous_id' => null,
            'event_state' => 'recorded',
            'category_id' => $f['category'],
            'occurred_at' => '2026-11-02 00:00:00+03',
            'description' => 'Water supply interruption',
            'recorded_by' => $f['user']->id,
            'assignment_id' => $f['assignment'],
        ], $override));
    }

    private function fixture(): array
    {
        $school = SchoolBuilder::create();
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $reviewer = UserBuilder::create($school, $role);

        $hostel = $this->insert('hostels', [
            'school_id' => $school->id,
            'hostel_name' => 'Daily reporting '.Str::random(10),
            'hostel_type' => 'BOYS',
            'capacity' => 20,
            'active' => true,
            'is_deleted' => false,
        ]);

        $f = compact('school', 'user', 'reviewer', 'hostel');
        $f['assignment'] = $this->assignment($f, (string) $user->id);

        $policy = $this->insert('boarding_reporting_policies', [
            'school_id' => $school->id,
            'revision' => 1,
            'enabled' => true,
            'effective_from' => '2026-11-01',
            'timezone' => 'Africa/Nairobi',
            'deadline_time' => '08:00',
            'deadline_day_offset' => 1,
            'grace_minutes' => 30,
            'weekday_mask' => 127,
            'created_by' => $user->id,
        ]);

        $f['obligation'] = $this->insert('boarding_daily_obligations', [
            'school_id' => $school->id,
            'hostel_id' => $hostel,
            'policy_id' => $policy,
            'reporting_date' => '2026-11-02',
            'created_by' => $user->id,
        ]);

        $f['report'] = $this->createReportHead($f);
        $f['revision'] = $this->draft($f);

        return $f;
    }

    private function assignment(array $f, string $userId): string
    {
        return $this->insert('hostel_staff_assignments', [
            'school_id' => $f['school']->id,
            'hostel_id' => $f['hostel'],
            'user_id' => $userId,
            'responsibility_role' => 'Warden',
            'effective_from' => '2026-11-01',
            'active' => true,
            'assigned_by' => $f['user']->id,
        ]);
    }

    private function createReportHead(array $f, array $override = []): string
    {
        return $this->insert('boarding_daily_reports', array_merge([
            'school_id' => $f['school']->id,
            'obligation_id' => $f['obligation'],
            'created_by' => $f['user']->id,
        ], $override));
    }

    private function draft(array $f, int $number = 1, array $override = []): string
    {
        return $this->insert('boarding_daily_report_revisions', array_merge([
            'school_id' => $f['school']->id,
            'report_id' => $f['report'],
            'revision_number' => $number,
            'summary' => 'Initial report',
            'no_occurrences' => true,
            'created_by' => $f['user']->id,
            'created_assignment_id' => $f['assignment'],
            'updated_by' => $f['user']->id,
            'updated_assignment_id' => $f['assignment'],
        ], $override));
    }

    private function submit(array $f, ?string $revision = null, array $override = []): string
    {
        return $this->insert('boarding_daily_report_submissions', array_merge([
            'school_id' => $f['school']->id,
            'revision_id' => $revision ?? $f['revision'],
            'submitted_by' => $f['user']->id,
            'assignment_id' => $f['assignment'],
        ], $override));
    }

    private function review(
        array $f,
        string $submission,
        string $decision = 'reviewed',
        array $override = []
    ): string {
        $revision = DB::table('boarding_daily_report_submissions')
            ->where('id', $submission)->value('revision_id');

        return $this->insert('boarding_daily_report_reviews', array_merge([
            'school_id' => $f['school']->id,
            'revision_id' => $revision,
            'submission_id' => $submission,
            'reviewed_by' => $f['reviewer']->id,
            'decision' => $decision,
            'comment' => $decision === 'changes_requested' ? 'Please correct the report.' : null,
        ], $override));
    }

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id] + $values);

        return $id;
    }

    private function rejected(callable $operation, string $sqlState): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('Invalid report evidence write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
    }
}
