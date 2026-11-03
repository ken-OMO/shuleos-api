<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use App\Services\Boarding\BoardingReportingPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

class BoardingReportingObligationHttpTest extends TestCase
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
            'jwt.secret' => str_repeat('boarding-obligation-http-secret-', 3),
            'jwt.ttl' => 60,
        ]);
        CarbonImmutable::setTestNow('2026-11-02T06:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_obligation_http_leadership_sees_missing_unassigned_reports_without_writes(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->grant($f['reviewer'], 'view_boarding_operations');

        $hostel = $this->insert('hostels', [
            'school_id' => $f['school']->id,
            'hostel_name' => 'Unassigned listing '.Str::random(10),
            'hostel_type' => 'GIRLS', 'capacity' => 20,
            'active' => true, 'is_deleted' => false,
        ]);
        app(BoardingReportingPolicyService::class)
            ->materializeToday($f['user']);

        $this->httpAs($f['reviewer']);
        $before = $this->obligationSnapshot();
        $rows = $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonCount(2, 'data')->json('data');
        $this->assertSame($before, $this->obligationSnapshot());

        $unassigned = collect($rows)->firstWhere('hostel_id', $hostel);
        $assigned = collect($rows)->firstWhere('hostel_id', $f['hostel']);
        $this->assertSame('missing', $unassigned['state']);
        $this->assertTrue($unassigned['unassigned']);
        $this->assertFalse($assigned['unassigned']);
        $this->assertNull($unassigned['report_id']);

        $this->httpAs($f['user']);
        $this->getJson($this->listingUrl())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hostel_id', $f['hostel']);
    }

    public function test_obligation_http_tenant_and_assignment_scopes_apply_both_ways(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'record_boarding_operations');
        }

        $before = $this->obligationSnapshot();
        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->httpAs($own['user']);
            $this->getJson($this->listingUrl())->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $own['obligation']);
            $this->getJson($this->listingUrl().'&hostel_id='.$foreign['hostel'])
                ->assertNotFound();
        }
        $this->assertSame($before, $this->obligationSnapshot());

        $this->grant($a['reviewer'], 'record_boarding_operations');
        $this->httpAs($a['reviewer']);
        $this->getJson($this->listingUrl())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_obligation_http_deadline_equality_and_overdue_are_derived_without_writes(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['reviewer'], 'view_boarding_operations');
        $this->httpAs($f['reviewer']);
        $before = $this->obligationSnapshot();
        $due = DB::table('boarding_daily_obligations')
            ->where('id', $f['obligation'])->value('due_at');

        CarbonImmutable::setTestNow(CarbonImmutable::parse($due));
        // Issue credentials at the simulated request time.
        $this->httpAs($f['reviewer']);
        $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonPath('data.0.overdue', false)
            ->assertJsonPath('data.0.late', false);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($due)->addSecond()
        );
        // Issue credentials at the simulated request time.
        $this->httpAs($f['reviewer']);
        $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonPath('data.0.overdue', true)
            ->assertJsonPath('data.0.state', 'missing');

        $this->assertSame($before, $this->obligationSnapshot());
    }

    public function test_obligation_http_reports_submitted_state_and_retains_historical_visibility(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $this->grant($f['reviewer'], 'view_boarding_operations');
        $reports = app(BoardingDailyReportService::class);
        $opened = $reports->open($f['user'], $f['obligation']);
        $draft = $reports->updateDraft(
            $f['user'], $opened['revision_id'], 1,
            ['summary' => 'Daily check.', 'no_occurrences' => true]
        );
        $reports->submit($f['user'], $draft['revision_id'], $draft['version']);

        DB::table('hostels')->where('id', $f['hostel'])->update(['active' => false]);
        DB::table('schools')->where('id', $f['school']->id)
            ->update(['operating_mode' => 'day']);

        $this->httpAs($f['reviewer']);
        $before = $this->obligationSnapshot();
        $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonPath('data.0.state', 'submitted')
            ->assertJsonPath('data.0.report_id', $opened['report_id'])
            ->assertJsonPath('data.0.overdue', false);
        $this->assertSame($before, $this->obligationSnapshot());
    }

    public function test_obligation_http_assignment_without_record_permission_is_unassigned(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['reviewer'], 'view_boarding_operations');
        $this->httpAs($f['reviewer']);
        $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonPath('data.0.unassigned', true);

        $this->grant($f['user'], 'record_boarding_operations');
        $this->getJson($this->listingUrl())->assertOk()
            ->assertJsonPath('data.0.unassigned', false);
    }

    public function test_obligation_http_authentication_permission_and_filters_are_checked(): void
    {
        $this->getJson($this->listingUrl())->assertUnauthorized();
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->httpAs($f['user']);
        $this->getJson($this->listingUrl())->assertForbidden();

        $this->grant($f['user'], 'view_boarding_operations');
        $before = $this->obligationSnapshot();
        $this->getJson('/api/boarding/reporting/obligations?from=2026-10-01&to=2026-11-02')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($this->listingUrl().'&per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($this->listingUrl().'&school_id='.$f['school']->id)
            ->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $this->assertSame($before, $this->obligationSnapshot());
    }

    private function listingUrl(): string
    {
        return '/api/boarding/reporting/obligations?from=2026-11-02&to=2026-11-02';
    }

    private function obligationSnapshot(): array
    {
        $snapshot = $this->serviceSnapshot();
        foreach (['boarding_reporting_policies', 'boarding_daily_obligations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
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
