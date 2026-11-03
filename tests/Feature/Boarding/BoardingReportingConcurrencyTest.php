<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoardingConcurrencyRunner;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingReportingConcurrencyTest extends TestCase
{
    private array $raceFixture = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase();

        $this->raceFixture = DB::transaction(function (): array {
            $f = $this->operationalFixture();
            $this->grant($f['user'], 'record_boarding_operations');
            $this->grant($f['reviewer'], 'review_boarding_reports');

            return $f;
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->raceFixture !== []) {
                $this->removeRaceFixture();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_open_creates_one_report_and_revision(): void
    {
        $outcome = $this->race(
            ['operation' => 'open'],
            ['operation' => 'open']
        );

        $this->assertFinished($outcome['first']);
        $this->assertFinished($outcome['second']);
        $this->assertSame(
            $outcome['first']['result'],
            $outcome['second']['result']
        );
        $this->assertSame(1, $this->owned('boarding_daily_reports')->count());
        $this->assertSame(1, $this->owned('boarding_daily_report_revisions')->count());
        $this->assertSame(1, $this->owned('boarding_daily_report_contributors')->count());
        $this->assertSame(1, $this->auditCount('report_opened'));
    }

    public function test_competing_edits_preserve_first_and_reject_stale_second(): void
    {
        $draft = $this->readyDraft();
        $base = [
            'operation' => 'edit',
            'revision_id' => $draft['revision_id'],
            'version' => $draft['version'],
        ];

        $outcome = $this->race(
            ['summary' => 'First committed edit.'] + $base,
            ['summary' => 'Stale competing edit.'] + $base
        );

        $this->assertFinished($outcome['first']);
        $this->assertConflict($outcome['second']);
        $row = $this->owned('boarding_daily_report_revisions')->first();
        $this->assertSame('First committed edit.', $row->summary);
        $this->assertSame($draft['version'] + 1, (int) $row->lock_version);
        $this->assertSame(0, $this->owned('boarding_daily_report_submissions')->count());
    }

    public function test_competing_submissions_create_one_frozen_snapshot(): void
    {
        $draft = $this->readyDraft();
        $payload = [
            'operation' => 'submit',
            'revision_id' => $draft['revision_id'],
            'version' => $draft['version'],
        ];

        $outcome = $this->race($payload, $payload);
        $this->assertFinished($outcome['first']);
        $this->assertFinished($outcome['second']);
        $this->assertSame(
            $outcome['first']['result']['submission_id'],
            $outcome['second']['result']['submission_id']
        );
        $this->assertSame(1, $this->owned('boarding_daily_report_submissions')->count());
        $this->assertSame(1, $this->auditCount('report_submitted'));

        $snapshot = json_decode(
            $this->owned('boarding_daily_report_submissions')->value('snapshot'),
            true, 512, JSON_THROW_ON_ERROR
        );
        $this->assertSame('Ready for concurrency.', $snapshot['summary']);
    }

    public function test_submission_first_blocks_waiting_draft_edit(): void
    {
        $draft = $this->readyDraft();
        $base = [
            'revision_id' => $draft['revision_id'],
            'version' => $draft['version'],
        ];

        $outcome = $this->race(
            ['operation' => 'submit'] + $base,
            ['operation' => 'edit', 'summary' => 'Forbidden rewrite.'] + $base
        );

        $this->assertFinished($outcome['first']);
        $this->assertConflict($outcome['second']);
        $this->assertSame(
            'Ready for concurrency.',
            $this->owned('boarding_daily_report_revisions')->value('summary')
        );
        $snapshot = json_decode(
            $this->owned('boarding_daily_report_submissions')->value('snapshot'),
            true, 512, JSON_THROW_ON_ERROR
        );
        $this->assertSame('Ready for concurrency.', $snapshot['summary']);
    }

    public function test_edit_first_blocks_waiting_stale_submission(): void
    {
        $draft = $this->readyDraft();
        $base = [
            'revision_id' => $draft['revision_id'],
            'version' => $draft['version'],
        ];

        $outcome = $this->race(
            ['operation' => 'edit', 'summary' => 'Latest draft.'] + $base,
            ['operation' => 'submit'] + $base
        );

        $this->assertFinished($outcome['first']);
        $this->assertConflict($outcome['second']);
        $this->assertSame(0, $this->owned('boarding_daily_report_submissions')->count());
        $this->assertSame(0, $this->auditCount('report_submitted'));
        $this->assertSame(
            'Latest draft.',
            $this->owned('boarding_daily_report_revisions')->value('summary')
        );
    }

    public function test_competing_review_decisions_preserve_first_terminal_review(): void
    {
        $submitted = $this->submittedReport();
        $payload = [
            'operation' => 'review',
            'actor_id' => $this->raceFixture['reviewer']->id,
            'submission_id' => $submitted['submission_id'],
        ];
        $snapshot = $this->owned('boarding_daily_report_submissions')->value('snapshot');

        $outcome = $this->race(
            ['decision' => 'reviewed'] + $payload,
            ['decision' => 'changes_requested'] + $payload
        );

        $this->assertFinished($outcome['first']);
        $this->assertConflict($outcome['second']);
        $this->assertSame(1, $this->owned('boarding_daily_report_reviews')->count());
        $this->assertSame(
            'reviewed',
            $this->owned('boarding_daily_report_reviews')->value('decision')
        );
        $this->assertSame(1, $this->auditCount('report_reviewed'));
        $this->assertSame(
            $snapshot,
            $this->owned('boarding_daily_report_submissions')->value('snapshot')
        );
    }

    public function test_competing_correction_open_creates_one_new_revision(): void
    {
        $submitted = $this->submittedReport();
        app(BoardingDailyReportService::class)->review(
            $this->raceFixture['reviewer'],
            $submitted['submission_id'],
            'changes_requested',
            'Clarify the report.'
        );
        $snapshot = $this->owned('boarding_daily_report_submissions')->value('snapshot');

        $payload = [
            'operation' => 'correction',
            'report_id' => $submitted['report_id'],
        ];
        $outcome = $this->race($payload, $payload);

        $this->assertFinished($outcome['first']);
        $this->assertFinished($outcome['second']);
        $this->assertSame(
            $outcome['first']['result']['revision_id'],
            $outcome['second']['result']['revision_id']
        );
        $this->assertSame(2, $this->owned('boarding_daily_report_revisions')->count());
        $this->assertSame(1, $this->auditCount('report_correction_opened'));
        $this->assertSame(
            $snapshot,
            $this->owned('boarding_daily_report_submissions')->value('snapshot')
        );
    }

    private function race(array $first, array $second): array
    {
        $base = [
            'school_id' => $this->raceFixture['school']->id,
            'actor_id' => $this->raceFixture['user']->id,
            'obligation_id' => $this->raceFixture['obligation'],
        ];

        $outcome = (new BoardingConcurrencyRunner)->run(
            $first + $base, $second + $base
        );
        $this->assertTrue($outcome['contention_verified']);

        return $outcome;
    }

    private function readyDraft(): array
    {
        $service = app(BoardingDailyReportService::class);
        $f = $this->raceFixture;
        $report = $service->open($f['user'], $f['obligation']);

        return $service->updateDraft(
            $f['user'], $report['revision_id'], $report['version'],
            ['summary' => 'Ready for concurrency.', 'no_occurrences' => true]
        );
    }

    private function submittedReport(): array
    {
        $draft = $this->readyDraft();

        return app(BoardingDailyReportService::class)->submit(
            $this->raceFixture['user'], $draft['revision_id'], $draft['version']
        );
    }

    private function assertFinished(array $result): void
    {
        $this->assertSame('finished', $result['event'], json_encode($result));
        $this->assertSame(0, $result['exit_code']);
    }

    private function assertConflict(array $result): void
    {
        $this->assertSame('rejected', $result['event'], json_encode($result));
        $this->assertSame(2, $result['exit_code']);
        $this->assertSame(409, $result['http_status'] ?? null);
    }

    private function owned(string $table): Builder
    {
        return DB::table($table)->where(
            'school_id', $this->raceFixture['school']->id
        );
    }

    private function auditCount(string $action): int
    {
        return $this->owned('audit_logs')->where('action', $action)->count();
    }

    private function assertIsolatedDatabase(): void
    {
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Exact isolated database and committed fixtures required.');
        }

        if (DB::selectOne('SELECT current_database() AS name')->name
            !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Unexpected actual database.');
        }
    }

    private function removeRaceFixture(): void
    {
        $this->assertIsolatedDatabase();
        $schoolId = $this->raceFixture['school']->id;
        $roleIds = [
            $this->raceFixture['user']->role_id,
            $this->raceFixture['reviewer']->role_id,
        ];

        $tables = [
            'boarding_daily_report_reviews',
            'boarding_daily_report_submissions',
            'boarding_report_occurrence_events',
            'boarding_daily_report_contributors',
            'boarding_daily_report_revisions',
            'boarding_daily_reports',
            'boarding_daily_obligations',
            'boarding_reporting_policies',
            'hostel_staff_assignments',
            'hostels',
            'audit_logs',
            'streams',
            'grades',
            'terms',
            'academic_years',
            'users',
            'schools',
            'role_permissions',
            'roles',
        ];

        DB::transaction(function () use ($tables, $schoolId, $roleIds): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            // Test-fixture teardown only. ALTER TABLE is transactional in PostgreSQL.
            // Foreign-key constraint triggers remain enabled.
            foreach ($tables as $table) {
                DB::statement('ALTER TABLE "'.$table.'" DISABLE TRIGGER USER');
            }

            foreach ($tables as $table) {
                $query = DB::table($table);

                if ($table === 'schools') {
                    $query->where('id', $schoolId)->delete();
                } elseif ($table === 'roles') {
                    $query->whereIn('id', $roleIds)->delete();
                } elseif ($table === 'role_permissions') {
                    $query->whereIn('role_id', $roleIds)->delete();
                } else {
                    $query->where('school_id', $schoolId)->delete();
                }
            }

            foreach (array_reverse($tables) as $table) {
                DB::statement('ALTER TABLE "'.$table.'" ENABLE TRIGGER USER');
            }
        });

        $this->raceFixture = [];
    }

    private function operationalFixture(): array
    {
        $f = $this->fixture(false);

        DB::table('schools')->where('id', $f['school']->id)->update([
            'short_name' => 'BRD',
            'registration_number' => 'REG-'.Str::random(12),
            'school_type' => 'Primary',
            'operating_mode' => 'day_and_boarding',
            'county' => 'Nairobi',
            'phone' => '+254700000001',
            'email' => 'office-'.Str::random(12).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'lifecycle_state' => 'active',
        ]);

        $year = AcademicYearBuilder::create($f['school']);
        TermBuilder::create($f['school'], $year);
        $grade = GradeBuilder::create($f['school']);
        StreamBuilder::create($f['school'], $grade);

        $f['user'] = User::withoutGlobalScopes()->findOrFail($f['user']->id);
        $f['reviewer'] = User::withoutGlobalScopes()->findOrFail($f['reviewer']->id);

        return $f;
    }

    private function grant(User $user, string $name): void
    {
        $id = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($id);

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $id,
            'created_at' => now(),
        ]);
    }

    private function fixture(bool $withReport = true): array
    {
        $school = SchoolBuilder::create();
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $reviewer = UserBuilder::create($school, RoleBuilder::create());

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

        if ($withReport) {
            $f['report'] = $this->createReportHead($f);
            $f['revision'] = $this->draft($f);
        }

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

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id] + $values);

        return $id;
    }
}
