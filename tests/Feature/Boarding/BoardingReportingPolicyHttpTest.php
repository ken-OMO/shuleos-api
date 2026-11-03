<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
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

class BoardingReportingPolicyHttpTest extends TestCase
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
            'jwt.secret' => str_repeat('boarding-policy-http-test-secret-', 3),
            'jwt.ttl' => 60,
        ]);
        CarbonImmutable::setTestNow('2026-11-02T06:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_policy_http_configures_prospectively_and_get_does_not_write(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->httpAs($f['user']);

        $before = $this->configurationSnapshot();
        $this->getJson('/api/boarding/reporting/policies')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($before, $this->configurationSnapshot());

        $obligationBefore = (array) DB::table('boarding_daily_obligations')
            ->where('id', $f['obligation'])->first();

        $this->postJson('/api/boarding/reporting/policies', $this->policyPayload())
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.effective_from', '2026-11-03')
            ->assertJsonPath('data.timezone', 'Africa/Nairobi');

        $this->assertSame(
            $obligationBefore,
            (array) DB::table('boarding_daily_obligations')
                ->where('id', $f['obligation'])->first()
        );

        $before = $this->configurationSnapshot();
        $this->postJson('/api/boarding/reporting/policies', $this->policyPayload())
            ->assertConflict();
        $this->assertSame($before, $this->configurationSnapshot());
    }

    public function test_policy_http_materializes_unassigned_hostel_once_without_creating_report(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $hostel = $this->insert('hostels', [
            'school_id' => $f['school']->id,
            'hostel_name' => 'HTTP unassigned '.Str::random(10),
            'hostel_type' => 'GIRLS',
            'capacity' => 20,
            'active' => true,
            'is_deleted' => false,
        ]);
        $this->httpAs($f['user']);

        $this->postJson('/api/boarding/reporting/obligations/materialize-today')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.reporting_date', '2026-11-02');

        $this->assertDatabaseHas('boarding_daily_obligations', [
            'school_id' => $f['school']->id,
            'hostel_id' => $hostel,
            'reporting_date' => '2026-11-02',
        ]);
        $this->assertSame(0, DB::table('boarding_daily_reports')
            ->where('school_id', $f['school']->id)->count());

        $before = $this->configurationSnapshot();
        $this->postJson('/api/boarding/reporting/obligations/materialize-today')
            ->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame($before, $this->configurationSnapshot());
    }

    public function test_policy_http_reporting_and_management_permissions_do_not_grant_configuration(): void
    {
        $f = $this->operationalFixture();
        foreach ([
            'manage_boarding', 'record_boarding_operations',
            'view_boarding_operations', 'review_boarding_reports',
        ] as $permission) {
            $this->grant($f['user'], $permission);
        }

        $this->httpAs($f['user']);
        $before = $this->configurationSnapshot();
        $this->getJson('/api/boarding/reporting/policies')->assertForbidden();
        $this->postJson('/api/boarding/reporting/policies', $this->policyPayload())
            ->assertForbidden();
        $this->postJson('/api/boarding/reporting/obligations/materialize-today')
            ->assertForbidden();
        $this->assertSame($before, $this->configurationSnapshot());
    }

    public function test_policy_http_rejects_client_authority_and_backdated_configuration(): void
    {
        $f = $this->operationalFixture();
        $foreign = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->httpAs($f['user']);
        $before = $this->configurationSnapshot();

        $this->postJson('/api/boarding/reporting/policies', $this->policyPayload() + [
            'school_id' => $foreign['school']->id,
            'timezone' => 'UTC',
            'created_by' => $foreign['user']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'school_id', 'timezone', 'created_by',
        ]);

        $payload = $this->policyPayload();
        $payload['effective_from'] = '2026-11-02';
        $this->postJson('/api/boarding/reporting/policies', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $this->postJson('/api/boarding/reporting/obligations/materialize-today', [
            'reporting_date' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('reporting_date');

        $this->assertSame($before, $this->configurationSnapshot());
    }

    public function test_policy_http_historical_configuration_read_survives_day_mode_but_writes_do_not(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        DB::table('schools')->where('id', $f['school']->id)
            ->update(['operating_mode' => 'day']);
        $this->httpAs($f['user']);
        $before = $this->configurationSnapshot();

        $this->getJson('/api/boarding/reporting/policies')->assertOk();
        $this->postJson('/api/boarding/reporting/policies', $this->policyPayload())
            ->assertForbidden();
        $this->postJson('/api/boarding/reporting/obligations/materialize-today')
            ->assertForbidden();

        $this->assertSame($before, $this->configurationSnapshot());
    }

    public function test_policy_http_requires_authentication_and_explicit_configuration_permission(): void
    {
        $this->getJson('/api/boarding/reporting/policies')->assertUnauthorized();

        foreach (['index', 'configure', 'materialize'] as $action) {
            $route = Route::getRoutes()->getByName('boarding.reporting.policies.'.$action);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('jwt', $middleware);
            $this->assertContains('tenant', $middleware);
            $this->assertContains('permission:configure_boarding_reporting', $middleware);
            $this->assertNotContains('module.permission', $middleware);
        }
    }

    private function policyPayload(): array
    {
        return [
            'expected_revision' => 1,
            'enabled' => true,
            'effective_from' => '2026-11-03',
            'deadline_time' => '09:00',
            'deadline_day_offset' => 1,
            'grace_minutes' => 15,
            'weekday_mask' => 127,
        ];
    }

    private function configurationSnapshot(): array
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
