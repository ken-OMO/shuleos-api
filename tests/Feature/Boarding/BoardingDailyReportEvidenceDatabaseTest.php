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

class BoardingDailyReportEvidenceDatabaseTest extends TestCase
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

    public function test_shared_report_and_correction_preserve_exact_submitted_revision(): void
    {
        $f = $this->fixture();
        $this->rejected(fn () => $this->createReportHead($f), '23505');

        $submission = $this->submit($f);
        $snapshot = DB::table('boarding_daily_report_submissions')
            ->where('id', $submission)->value('snapshot');

        $this->review($f, $submission, 'changes_requested');

        $revision = $this->draft($f, 2, ['summary' => 'Corrected report']);
        $corrected = $this->submit($f, $revision);
        $this->review($f, $corrected);

        $this->assertSame(
            $snapshot,
            DB::table('boarding_daily_report_submissions')
                ->where('id', $submission)->value('snapshot')
        );
        $this->assertSame(
            'Initial report',
            json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['summary']
        );
        $this->assertSame(2, DB::table('boarding_daily_report_submissions')
            ->where('school_id', $f['school']->id)->count());

        $this->rejected(fn () => $this->draft($f, 3), '23514');
    }

    public function test_foreign_report_obligation_actor_and_assignment_fail_both_ways(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->rejected(
                fn () => $this->createReportHead($own, ['obligation_id' => $foreign['obligation']]),
                '23503'
            );

            $this->rejected(fn () => $this->draft($own, 1, [
                'report_id' => $foreign['report'],
            ]), '23503');

            $this->rejected(fn () => $this->submit($own, null, [
                'submitted_by' => $foreign['user']->id,
                'assignment_id' => $foreign['assignment'],
            ]), '23503');

            $submission = $this->submit($own);

            $this->rejected(fn () => $this->review($own, $submission, 'reviewed', [
                'reviewed_by' => $foreign['reviewer']->id,
            ]), '23503');
        }
    }

    public function test_draft_versions_track_contributors_and_block_self_review(): void
    {
        $f = $this->fixture();
        $assignment = $this->assignment($f, (string) $f['reviewer']->id);

        $this->rejected(fn () => DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])->update(['summary' => 'Stale write']), '23514');

        DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])->update([
                'summary' => 'Shared contribution',
                'lock_version' => 2,
                'updated_by' => $f['reviewer']->id,
                'updated_assignment_id' => $assignment,
            ]);

        $this->assertSame(2, DB::table('boarding_daily_report_contributors')
            ->where('revision_id', $f['revision'])->count());

        $submission = $this->submit($f);

        foreach ([$f['user']->id, $f['reviewer']->id] as $actor) {
            $this->rejected(fn () => $this->review($f, $submission, 'reviewed', [
                'reviewed_by' => $actor,
            ]), '23514');
        }
    }

    public function test_new_revision_requires_changes_requested_and_submission_requires_summary(): void
    {
        $f = $this->fixture();

        $this->rejected(fn () => $this->draft($f, 2), '23514');

        DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])
            ->update(['summary' => '', 'lock_version' => 2]);

        $this->rejected(fn () => $this->submit($f), '23514');

        DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])
            ->update(['summary' => 'Ready', 'lock_version' => 3]);

        $submission = $this->submit($f);

        $this->rejected(fn () => $this->review($f, $submission, 'changes_requested', [
            'comment' => '',
        ]), '23514');

        $this->rejected(fn () => $this->draft($f, 2), '23514');

        $this->review($f, $submission, 'changes_requested');
        $this->assertNotEmpty($this->draft($f, 2));
    }

    public function test_submitted_content_and_evidence_are_immutable_and_snapshot_is_derived(): void
    {
        $f = $this->fixture();

        $this->rejected(fn () => $this->submit($f, null, [
            'snapshot' => json_encode(['summary' => 'Forged'], JSON_THROW_ON_ERROR),
        ]), '23514');

        $submission = $this->submit($f);
        $review = $this->review($f, $submission);
        $contributor = DB::table('boarding_daily_report_contributors')
            ->where('revision_id', $f['revision'])->value('id');

        foreach ([
            ['boarding_daily_reports', $f['report']],
            ['boarding_daily_report_contributors', $contributor],
            ['boarding_daily_report_submissions', $submission],
            ['boarding_daily_report_reviews', $review],
        ] as [$table, $id]) {
            $timestamp = match ($table) {
                'boarding_daily_report_reviews' => 'reviewed_at',
                'boarding_daily_report_submissions' => 'submitted_at',
                default => 'created_at',
            };
            $this->rejected(fn () => DB::table($table)->where('id', $id)
                ->update([$timestamp => now()]), '23514');
            $this->rejected(fn () => DB::table($table)->where('id', $id)->delete(), '23514');
        }

        $this->rejected(fn () => DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])
            ->update(['summary' => 'Rewrite', 'lock_version' => 2]), '23514');

        $this->rejected(fn () => DB::table('boarding_daily_report_revisions')
            ->where('id', $f['revision'])->delete(), '23514');

        // Use an existing review column to exercise its immutability trigger.
        $this->rejected(fn () => DB::table('boarding_daily_report_reviews')
            ->where('id', $review)->update(['comment' => 'Rewrite']), '23514');

        $snapshot = DB::table('boarding_daily_report_submissions')
            ->where('id', $submission)->value('snapshot');

        DB::table('hostels')->where('id', $f['hostel'])
            ->update(['hostel_name' => 'Renamed later']);

        $this->assertSame(
            $snapshot,
            DB::table('boarding_daily_report_submissions')
                ->where('id', $submission)->value('snapshot')
        );
    }

    public function test_populated_report_rollback_is_refused(): void
    {
        $f = $this->fixture();
        $migration = require database_path(
            'migrations/2026_11_02_120000_create_boarding_daily_report_evidence.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback discarded report evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Retain boarding report evidence',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('boarding_daily_reports', ['id' => $f['report']]);
    }

    public function test_empty_report_schema_can_roll_back_and_remigrate(): void
    {
        DB::beginTransaction();

        try {
            $migration = require database_path(
                'migrations/2026_11_02_120000_create_boarding_daily_report_evidence.php'
            );
            $occurrencesMigration = require database_path(
                'migrations/2026_11_02_130000_add_boarding_report_occurrences.php'
            );
            $occurrencesMigration->down();
            $migration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('boarding_daily_reports'));
            $migration->up();
            $occurrencesMigration->up();

            $f = $this->fixture();
            $this->assertNotEmpty($this->submit($f));
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('boarding_daily_reports'));
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
