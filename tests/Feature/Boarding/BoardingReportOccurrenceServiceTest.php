<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use App\Services\Boarding\BoardingReportOccurrenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingReportOccurrenceServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! in_array(
                DB::selectOne('SELECT current_database() AS name')->name,
                ['shuleos_assessment_fk_test', 'shuleos_testing'],
                true
            )) {
            throw new \RuntimeException('An isolated PostgreSQL test database is required.');
        }
    }

    public function test_occurrence_service_preserves_corrections_and_submission_snapshots(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['reviewer'], 'review_boarding_reports');
        $reports = app(BoardingDailyReportService::class);
        $service = app(BoardingReportOccurrenceService::class);
        $opened = $reports->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);

        $recorded = $service->record(
            $f['user'], $opened['revision_id'], $opened['version'], $data
        );
        $this->assertSame(2, $recorded['version']);
        $this->assertCount(1, $recorded['occurrences']);
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $opened['revision_id'])->first();

        $draft = $reports->updateDraft(
            $f['user'], $recorded['revision_id'], $recorded['version'],
            ['summary' => 'Initial submission', 'no_occurrences' => false]
        );
        $submitted = $reports->submit(
            $f['user'], $draft['revision_id'], $draft['version']
        );
        $snapshot = DB::table('boarding_daily_report_submissions')
            ->where('id', $submitted['submission_id'])->value('snapshot');

        $reports->review(
            $f['reviewer'], $submitted['submission_id'],
            'changes_requested', 'Correct the description.'
        );
        $correction = $reports->openCorrection($f['user'], $opened['report_id']);

        $data['description'] = 'Corrected facilities observation.';
        $corrected = $service->correct(
            $f['user'], $correction['revision_id'], $correction['version'],
            $event->event_id, 1, $data, 'Corrected the recorded detail.'
        );
        $this->assertCount(1, $corrected['occurrences']);
        $this->assertSame(2, DB::table('boarding_report_occurrence_events')
            ->where('report_id', $opened['report_id'])->count());

        $resubmitted = $reports->submit(
            $f['user'], $corrected['revision_id'], $corrected['version']
        );
        $this->assertSame(
            'Corrected facilities observation.',
            $resubmitted['snapshot']['occurrences'][0]['description']
        );
        $this->assertSame($snapshot, DB::table('boarding_daily_report_submissions')
            ->where('id', $submitted['submission_id'])->value('snapshot'));
    }

    public function test_occurrence_service_void_preserves_content_and_requires_explicit_declaration(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $reports = app(BoardingDailyReportService::class);
        $service = app(BoardingReportOccurrenceService::class);
        $opened = $reports->open($f['user'], $f['obligation']);
        $recorded = $service->record(
            $f['user'], $opened['revision_id'], 1, $this->occurrenceData($f)
        );
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $opened['revision_id'])->first();

        $voided = $service->void(
            $f['user'], $opened['revision_id'], $recorded['version'],
            $event->event_id, 1, 'This observation was entered in error.'
        );
        $latest = DB::table('boarding_report_occurrence_events')
            ->where('event_id', $event->event_id)->orderByDesc('version')->first();
        $this->assertSame('voided', $latest->event_state);
        $this->assertSame($event->description, $latest->description);
        $this->assertFalse($voided['no_occurrences']);

        $draft = $reports->updateDraft(
            $f['user'], $opened['revision_id'], $voided['version'],
            ['summary' => 'Checked; erroneous entry voided.', 'no_occurrences' => true]
        );
        $submitted = $reports->submit(
            $f['user'], $draft['revision_id'], $draft['version']
        );
        $this->assertSame('voided', $submitted['snapshot']['occurrences'][0]['event_state']);
    }

    public function test_occurrence_service_rejects_foreign_categories_and_revisions_both_ways(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        $service = app(BoardingReportOccurrenceService::class);

        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'record_boarding_operations');
        }

        $a['opened'] = app(BoardingDailyReportService::class)->open($a['user'], $a['obligation']);
        $b['opened'] = app(BoardingDailyReportService::class)->open($b['user'], $b['obligation']);
        $a['data'] = $this->occurrenceData($a);
        $b['data'] = $this->occurrenceData($b);
        $before = $this->serviceSnapshot();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->denied(fn () => $service->record(
                $own['user'], $foreign['opened']['revision_id'], 1, $own['data']
            ), 404);
            $this->denied(fn () => $service->record(
                $own['user'], $own['opened']['revision_id'], 1, $foreign['data']
            ), 404);
            $this->denied(fn () => $service->read(
                $own['user'], $foreign['opened']['report_id']
            ), 404);
        }

        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_occurrence_service_rejects_stale_versions_and_submitted_writes(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $reports = app(BoardingDailyReportService::class);
        $service = app(BoardingReportOccurrenceService::class);
        $opened = $reports->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);
        $recorded = $service->record($f['user'], $opened['revision_id'], 1, $data);
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $opened['revision_id'])->first();
        $before = $this->serviceSnapshot();

        $this->denied(fn () => $service->record(
            $f['user'], $opened['revision_id'], 1, $data
        ), 409);
        $this->denied(fn () => $service->correct(
            $f['user'], $opened['revision_id'], $recorded['version'],
            $event->event_id, 99, $data, 'Stale occurrence.'
        ), 409);
        $this->assertSame($before, $this->serviceSnapshot());

        $draft = $reports->updateDraft(
            $f['user'], $opened['revision_id'], $recorded['version'],
            ['summary' => 'Ready.', 'no_occurrences' => false]
        );
        $reports->submit($f['user'], $draft['revision_id'], $draft['version']);
        $before = $this->serviceSnapshot();
        $this->denied(fn () => $service->record(
            $f['user'], $draft['revision_id'], $draft['version'], $data
        ), 409);
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_occurrence_service_validates_time_follow_up_and_pending_learner_links(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $service = app(BoardingReportOccurrenceService::class);
        $opened = app(BoardingDailyReportService::class)->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);
        $before = $this->serviceSnapshot();

        foreach ([
            ['occurred_at', '2026-11-01T00:30:00+03:00', 'occurred_at'],
            ['occurred_at', '2099-11-02T00:30:00+03:00', 'occurred_at'],
            ['follow_up_required', true, 'follow_up_notes'],
            ['learner_id', (string) Str::uuid(), 'learner_id'],
        ] as [$field, $value, $error]) {
            try {
                $service->record(
                    $f['user'], $opened['revision_id'], 1,
                    array_replace($data, [$field => $value])
                );
                $this->fail('Invalid occurrence was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($error, $exception->errors());
            }
        }

        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_occurrence_service_audit_failure_rolls_back_event_draft_and_contributors(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $service = app(BoardingReportOccurrenceService::class);
        $opened = app(BoardingDailyReportService::class)->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);
        $before = $this->serviceSnapshot();

        $this->failingAudit(fn () => $service->record(
            $f['user'], $opened['revision_id'], 1, $data
        ));
        $this->assertSame($before, $this->serviceSnapshot());

        $recorded = $service->record($f['user'], $opened['revision_id'], 1, $data);
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $opened['revision_id'])->first();
        $before = $this->serviceSnapshot();

        $this->failingAudit(fn () => $service->correct(
            $f['user'], $opened['revision_id'], $recorded['version'],
            $event->event_id, 1, $data, 'Correction audit rollback.'
        ));
        $this->assertSame($before, $this->serviceSnapshot());

        $this->failingAudit(fn () => $service->void(
            $f['user'], $opened['revision_id'], $recorded['version'],
            $event->event_id, 1, 'Void audit rollback.'
        ));
        $this->assertSame($before, $this->serviceSnapshot());
    }

    private function occurrenceData(array $f): array
    {
        $category = $this->insert('boarding_occurrence_categories', [
            'school_id' => $f['school']->id,
            'category_name' => 'Facilities '.Str::random(10),
            'active' => true,
            'created_by' => $f['user']->id,
        ]);

        return [
            'category_id' => $category,
            'occurred_at' => '2026-11-02T00:30:00+03:00',
            'description' => 'Facilities observation.',
            'actions_taken' => 'Recorded for follow-up.',
            'follow_up_required' => false,
            'follow_up_notes' => '',
        ];
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
