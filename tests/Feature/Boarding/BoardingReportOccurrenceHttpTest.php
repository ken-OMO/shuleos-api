<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingReportOccurrenceHttpTest extends TestCase
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

        config([
            'jwt.secret' => str_repeat('boarding-occurrence-http-secret-', 3),
            'jwt.ttl' => 60,
        ]);
    }

    public function test_http_occurrence_record_correct_and_void_preserve_evidence(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $report = app(BoardingDailyReportService::class)
            ->open($f['user'], $f['obligation']);
        $this->httpAs($f['user']);
        $url = '/api/boarding/reporting/revisions/'.$report['revision_id'].'/occurrences';
        $data = $this->occurrenceData($f);

        $recorded = $this->postJson($url, $data + ['version' => 1])
            ->assertOk()->assertJsonCount(1, 'data.occurrences')->json('data');
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $report['revision_id'])->first();

        $data['description'] = 'Corrected through HTTP.';
        $corrected = $this->postJson($url.'/'.$event->event_id.'/corrections', $data + [
            'version' => $recorded['version'],
            'event_version' => 1,
            'reason' => 'Corrected the observation.',
        ])->assertOk()->json('data');

        $voided = $this->postJson($url.'/'.$event->event_id.'/void', [
            'version' => $corrected['version'],
            'event_version' => 2,
            'reason' => 'Observation entered in error.',
        ])->assertOk()->json('data');

        $latest = DB::table('boarding_report_occurrence_events')
            ->where('event_id', $event->event_id)->orderByDesc('version')->first();
        $this->assertSame('voided', $latest->event_state);
        $this->assertSame('Corrected through HTTP.', $latest->description);
        $this->assertSame(3, DB::table('boarding_report_occurrence_events')
            ->where('event_id', $event->event_id)->count());
        $this->assertFalse($voided['no_occurrences']);

        $before = $this->serviceSnapshot();
        $this->getJson(
            '/api/boarding/reporting/reports/'.$report['report_id'].'/occurrences'
        )->assertOk()->assertJsonCount(1, 'data.occurrences');
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_http_occurrence_rejects_foreign_resources_in_both_directions(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'record_boarding_operations');
        }
        $a['report'] = app(BoardingDailyReportService::class)->open($a['user'], $a['obligation']);
        $b['report'] = app(BoardingDailyReportService::class)->open($b['user'], $b['obligation']);
        $a['data'] = $this->occurrenceData($a);
        $b['data'] = $this->occurrenceData($b);
        $before = $this->serviceSnapshot();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->httpAs($own['user']);

            $this->postJson(
                '/api/boarding/reporting/revisions/'.$foreign['report']['revision_id'].'/occurrences',
                $own['data'] + ['version' => 1]
            )->assertNotFound();

            $this->postJson(
                '/api/boarding/reporting/revisions/'.$own['report']['revision_id'].'/occurrences',
                $foreign['data'] + ['version' => 1]
            )->assertNotFound();

            $this->getJson(
                '/api/boarding/reporting/reports/'.$foreign['report']['report_id'].'/occurrences'
            )->assertNotFound();
        }

        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_http_occurrence_rejects_learner_links_server_fields_and_stale_versions(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $report = app(BoardingDailyReportService::class)->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);
        $this->httpAs($f['user']);
        $url = '/api/boarding/reporting/revisions/'.$report['revision_id'].'/occurrences';
        $before = $this->serviceSnapshot();

        $this->postJson($url, $data + [
            'version' => 1,
            'learner_id' => (string) Str::uuid(),
            'recorded_by' => $f['reviewer']->id,
            'school_id' => $f['school']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'learner_id', 'recorded_by', 'school_id',
        ]);

        $this->postJson($url, $data + ['version' => 99])->assertConflict();
        $this->assertSame($before, $this->serviceSnapshot());

        $recorded = $this->postJson($url, $data + ['version' => 1])
            ->assertOk()->json('data');
        $event = DB::table('boarding_report_occurrence_events')
            ->where('revision_id', $report['revision_id'])->first();
        $before = $this->serviceSnapshot();

        $this->postJson($url.'/'.$event->event_id.'/void', [
            'version' => $recorded['version'],
            'event_version' => 1,
            'reason' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_http_occurrence_requires_assignment_and_submitted_content_cannot_change(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['reviewer'], 'record_boarding_operations');
        $reports = app(BoardingDailyReportService::class);
        $report = $reports->open($f['user'], $f['obligation']);
        $data = $this->occurrenceData($f);
        $url = '/api/boarding/reporting/revisions/'.$report['revision_id'].'/occurrences';

        $this->httpAs($f['reviewer']);
        $before = $this->serviceSnapshot();
        $this->postJson($url, $data + ['version' => 1])->assertForbidden();
        $this->assertSame($before, $this->serviceSnapshot());

        $this->httpAs($f['user']);
        $recorded = $this->postJson($url, $data + ['version' => 1])
            ->assertOk()->json('data');

        $draft = $reports->updateDraft(
            $f['user'], $report['revision_id'], $recorded['version'],
            ['summary' => 'Ready for review.', 'no_occurrences' => false]
        );
        $reports->submit($f['user'], $draft['revision_id'], $draft['version']);
        $before = $this->serviceSnapshot();

        $this->postJson($url, $data + ['version' => $draft['version']])
            ->assertConflict();
        $this->assertSame($before, $this->serviceSnapshot());
    }

    public function test_http_occurrence_routes_require_authentication_and_record_permission(): void
    {
        $this->getJson(
            '/api/boarding/reporting/reports/'.Str::uuid().'/occurrences'
        )->assertUnauthorized();

        foreach (['index', 'store', 'correct', 'void'] as $action) {
            $route = Route::getRoutes()->getByName('boarding.reporting.occurrences.'.$action);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('jwt', $middleware);
            $this->assertContains('tenant', $middleware);
            $this->assertNotContains('module.permission', $middleware);
            if ($action !== 'index') {
                $this->assertContains('permission:record_boarding_operations', $middleware);
            }
        }
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

    private function httpAs(User $user): void
    {
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
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
