<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingDailyReportServiceTest extends TestCase
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

    public function test_service_report_lifecycle_and_idempotency_preserve_submissions(): void
    {
        $service = app(BoardingDailyReportService::class);

        foreach ([$this->operationalFixture(), $this->operationalFixture()] as $f) {
            $this->grant($f['user'], 'record_boarding_operations');
            $this->grant($f['reviewer'], 'review_boarding_reports');

            $report = $service->open($f['user'], $f['obligation']);
            $before = $this->serviceSnapshot();
            $this->assertSame($report, $service->open($f['user'], $f['obligation']));
            $this->assertSame($before, $this->serviceSnapshot());

            $report = $service->updateDraft(
                $f['user'], $report['revision_id'], $report['version'],
                ['summary' => 'All boarding facilities checked.', 'no_occurrences' => true]
            );
            $report = $service->submit($f['user'], $report['revision_id'], $report['version']);
            $this->assertSame('submitted', $report['state']);
            $original = $report['snapshot'];
            $submissionId = $report['submission_id'];

            $before = $this->serviceSnapshot();
            $service->submit($f['user'], $report['revision_id'], $report['version']);
            $this->assertSame($before, $this->serviceSnapshot());

            $reviewed = $service->review(
                $f['reviewer'], $submissionId, 'changes_requested', 'Clarify the checks.'
            );
            $this->assertSame('changes_requested', $reviewed['state']);

            $correction = $service->openCorrection($f['user'], $report['report_id']);
            $this->assertSame(2, $correction['revision_number']);

            $before = $this->serviceSnapshot();
            $service->openCorrection($f['user'], $report['report_id']);
            $this->assertSame($before, $this->serviceSnapshot());

            $correction = $service->updateDraft(
                $f['user'], $correction['revision_id'], $correction['version'],
                ['summary' => 'Water, lighting and doors checked.', 'no_occurrences' => true]
            );
            $correction = $service->submit(
                $f['user'], $correction['revision_id'], $correction['version']
            );
            $result = $service->review($f['reviewer'], $correction['submission_id'], 'reviewed');
            $this->assertSame('reviewed', $result['state']);

            $stored = DB::table('boarding_daily_report_submissions')
                ->where('id', $submissionId)->value('snapshot');
            $this->assertSame($original, json_decode($stored, true, 512, JSON_THROW_ON_ERROR));
            $this->denied(
                fn () => $service->openCorrection($f['user'], $report['report_id']), 409
            );
        }
    }

    public function test_service_permission_and_responsibility_are_both_required(): void
    {
        $f = $this->operationalFixture();
        $service = app(BoardingDailyReportService::class);
        $before = $this->serviceSnapshot();

        $this->denied(fn () => $service->open($f['user'], $f['obligation']), 403);
        $this->assertSame($before, $this->serviceSnapshot());

        $this->grant($f['user'], 'record_boarding_operations');

        DB::table('hostel_staff_assignments')->where('id', $f['assignment'])->update([
            'active' => false,
            'effective_to' => '2026-11-01',
            'ended_by' => $f['user']->id,
            'ended_at' => now(),
            'end_reason' => 'Responsibility ended before reporting date.',
        ]);

        $before = $this->serviceSnapshot();
        $this->denied(fn () => $service->open($f['user'], $f['obligation']), 403);
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_service_foreign_reads_and_writes_fail_both_ways(): void
    {
        $service = app(BoardingDailyReportService::class);
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();

        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'record_boarding_operations');
            $this->grant($f['user'], 'view_boarding_operations');
            $this->grant($f['reviewer'], 'review_boarding_reports');
        }

        $a['opened'] = $service->open($a['user'], $a['obligation']);
        $b['opened'] = $service->open($b['user'], $b['obligation']);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $before = $this->serviceSnapshot();
            $this->denied(fn () => $service->read(
                $own['user'], $foreign['opened']['report_id']
            ), 404);
            $this->denied(fn () => $service->open($own['user'], $foreign['obligation']), 404);
            $this->denied(fn () => $service->updateDraft(
                $own['user'], $foreign['opened']['revision_id'], 1,
                ['summary' => 'Foreign write', 'no_occurrences' => true]
            ), 404);
            $this->assertSame($before, $this->serviceSnapshot());
        }
    }

    public function test_service_stale_edits_and_self_review_are_rejected(): void
    {
        $f = $this->operationalFixture();
        $service = app(BoardingDailyReportService::class);
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['user'], 'review_boarding_reports');

        $report = $service->open($f['user'], $f['obligation']);
        $report = $service->updateDraft(
            $f['user'], $report['revision_id'], 1,
            ['summary' => 'Ready for review', 'no_occurrences' => true]
        );

        $before = $this->serviceSnapshot();
        $this->denied(fn () => $service->updateDraft(
            $f['user'], $report['revision_id'], 1,
            ['summary' => 'Stale replacement', 'no_occurrences' => true]
        ), 409);
        $this->assertSame($before, $this->serviceSnapshot());

        $report = $service->submit($f['user'], $report['revision_id'], $report['version']);
        $before = $this->serviceSnapshot();

        $this->denied(
            fn () => $service->review($f['user'], $report['submission_id'], 'reviewed'), 403
        );
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_audit_failure_rolls_back_draft_review_and_correction(): void
    {
        $f = $this->operationalFixture();
        $service = app(BoardingDailyReportService::class);
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['reviewer'], 'review_boarding_reports');

        $report = $service->open($f['user'], $f['obligation']);

        $before = $this->serviceSnapshot();
        $this->failingAudit(fn () => $service->updateDraft(
            $f['user'], $report['revision_id'], $report['version'],
            ['summary' => 'Must roll back.', 'no_occurrences' => true]
        ));
        $this->assertSame($before, $this->serviceSnapshot());

        $draft = $service->updateDraft(
            $f['user'], $report['revision_id'], $report['version'],
            ['summary' => 'Ready for review.', 'no_occurrences' => true]
        );
        $submitted = $service->submit(
            $f['user'], $draft['revision_id'], $draft['version']
        );

        foreach (['reviewed', 'changes_requested'] as $decision) {
            $before = $this->serviceSnapshot();
            $this->failingAudit(fn () => $service->review(
                $f['reviewer'], $submitted['submission_id'],
                $decision, 'Review audit rollback.'
            ));
            $this->assertSame($before, $this->serviceSnapshot());
        }

        $service->review(
            $f['reviewer'], $submitted['submission_id'],
            'changes_requested', 'Clarify the report.'
        );

        $before = $this->serviceSnapshot();
        $this->failingAudit(fn () => $service->openCorrection(
            $f['user'], $report['report_id']
        ));
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_service_audit_failure_rolls_back_report_creation_and_submission(): void
    {
        $f = $this->operationalFixture();
        $service = app(BoardingDailyReportService::class);
        $this->grant($f['user'], 'record_boarding_operations');

        $before = $this->serviceSnapshot();
        $this->failingAudit(fn () => $service->open($f['user'], $f['obligation']));
        $this->assertSame($before, $this->serviceSnapshot());

        $report = $service->open($f['user'], $f['obligation']);
        $report = $service->updateDraft(
            $f['user'], $report['revision_id'], $report['version'],
            ['summary' => 'Ready', 'no_occurrences' => true]
        );

        $before = $this->serviceSnapshot();
        $this->failingAudit(fn () => $service->submit(
            $f['user'], $report['revision_id'], $report['version']
        ));
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_service_historical_leadership_read_survives_day_mode_but_writes_do_not(): void
    {
        $f = $this->operationalFixture();
        $service = app(BoardingDailyReportService::class);
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['reviewer'], 'view_boarding_operations');

        $report = $service->open($f['user'], $f['obligation']);
        DB::table('schools')->where('id', $f['school']->id)->update(['operating_mode' => 'day']);

        $this->assertSame(
            $report['report_id'],
            $service->read($f['reviewer'], $report['report_id'])['report_id']
        );
        $before = $this->serviceSnapshot();
        $this->denied(fn () => $service->updateDraft(
            $f['user'], $report['revision_id'], 1,
            ['summary' => 'Blocked', 'no_occurrences' => true]
        ), 403);
        $this->assertSame($before, $this->serviceSnapshot());
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

    private function serviceSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'boarding_daily_reports', 'boarding_daily_report_revisions',
            'boarding_daily_report_contributors', 'boarding_daily_report_submissions',
            'boarding_daily_report_reviews', 'boarding_report_occurrence_events',
            'audit_logs', 'fee_invoices', 'payments', 'learner_fee_ledger',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unauthorized or conflicting reporting operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function failingAudit(callable $operation): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Reporting audit failure test.');
            });

            try {
                $operation();
                $this->fail('A failed audit did not abort the reporting mutation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Reporting audit failure test.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
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
