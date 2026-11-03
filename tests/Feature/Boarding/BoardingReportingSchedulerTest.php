<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingReportingSchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
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

class BoardingReportingSchedulerTest extends TestCase
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

        CarbonImmutable::setTestNow('2026-11-02T06:00:00Z');
        config([
            'boarding_reporting.scheduler_enabled' => false,
            'boarding_reporting.scheduler_actors' => [],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_scheduler_disabled_run_and_command_do_not_write(): void
    {
        $f = $this->operationalFixture();
        config(['boarding_reporting.scheduler_actors' => [
            $f['school']->id => $f['user']->id,
        ]]);
        $before = $this->policySnapshot();

        $result = app(BoardingReportingSchedulerService::class)->run();
        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $result['created']);

        $this->artisan('boarding:materialize-reporting-obligations')
            ->expectsOutputToContain('scheduler disabled')
            ->assertExitCode(0);

        $this->assertSame($before, $this->policySnapshot());
    }

    public function test_scheduler_materializes_configured_schools_idempotently(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        $actors = [];

        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'configure_boarding_reporting');
            $this->extraHostel($f);
            $actors[$f['school']->id] = $f['user']->id;
        }

        config([
            'boarding_reporting.scheduler_enabled' => true,
            'boarding_reporting.scheduler_actors' => $actors,
        ]);
        $service = app(BoardingReportingSchedulerService::class);
        $result = $service->run();

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(2, $result['schools']);
        $this->assertSame(0, DB::table('boarding_daily_reports')
            ->whereIn('school_id', array_keys($actors))->count());

        $before = $this->policySnapshot();
        $this->assertSame(0, $service->run()['created']);
        $this->assertSame($before, $this->policySnapshot());
    }

    public function test_scheduler_foreign_actor_mapping_fails_without_mutation(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        $this->grant($b['user'], 'configure_boarding_reporting');
        $this->extraHostel($a);
        config([
            'boarding_reporting.scheduler_enabled' => true,
            'boarding_reporting.scheduler_actors' => [
                $a['school']->id => $b['user']->id,
            ],
        ]);
        $before = $this->policySnapshot();

        $this->artisan('boarding:materialize-reporting-obligations')
            ->expectsOutputToContain('failed=1')
            ->assertExitCode(1);

        $this->assertSame($before, $this->policySnapshot());
    }

    public function test_scheduler_rechecks_permission_and_school_write_gates(): void
    {
        $f = $this->operationalFixture();
        $this->extraHostel($f);
        config([
            'boarding_reporting.scheduler_enabled' => true,
            'boarding_reporting.scheduler_actors' => [
                $f['school']->id => $f['user']->id,
            ],
        ]);
        $service = app(BoardingReportingSchedulerService::class);
        $before = $this->policySnapshot();

        $this->assertSame(1, $service->run()['failed']);
        $this->assertSame($before, $this->policySnapshot());

        $this->grant($f['user'], 'configure_boarding_reporting');
        DB::table('schools')->where('id', $f['school']->id)
            ->update(['operating_mode' => 'day']);
        $before = $this->policySnapshot();
        $this->assertSame(1, $service->run()['failed']);
        $this->assertSame($before, $this->policySnapshot());
    }

    public function test_scheduler_audit_failure_rolls_back_school_obligations(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->extraHostel($f);
        config([
            'boarding_reporting.scheduler_enabled' => true,
            'boarding_reporting.scheduler_actors' => [
                $f['school']->id => $f['user']->id,
            ],
        ]);

        $before = $this->policySnapshot();
        $this->failingAudit(fn () => app(
            BoardingReportingSchedulerService::class
        )->run());
        $this->assertSame($before, $this->policySnapshot());
    }

    public function test_scheduler_mapping_validation_precedes_writes_and_schedule_is_registered(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->extraHostel($f);
        config([
            'boarding_reporting.scheduler_enabled' => true,
            'boarding_reporting.scheduler_actors' => [
                $f['school']->id => $f['user']->id,
                'invalid-school' => 'invalid-user',
            ],
        ]);
        $before = $this->policySnapshot();

        try {
            app(BoardingReportingSchedulerService::class)->run();
            $this->fail('Invalid scheduler mapping was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('require school and user UUIDs', $exception->getMessage());
        }
        $this->assertSame($before, $this->policySnapshot());

        config(['boarding_reporting.scheduler_enabled' => false]);
        $this->artisan('boarding:materialize-reporting-obligations')->assertExitCode(0);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains(
                $event->command ?? '', 'boarding:materialize-reporting-obligations'
            ));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function extraHostel(array $f): string
    {
        return $this->insert('hostels', [
            'school_id' => $f['school']->id,
            'hostel_name' => 'Scheduled '.Str::random(10),
            'hostel_type' => 'BOYS', 'capacity' => 20,
            'active' => true, 'is_deleted' => false,
        ]);
    }

    private function policyData(string $date = '2026-11-03'): array
    {
        return [
            'enabled' => true,
            'effective_from' => $date,
            'deadline_time' => '09:00',
            'deadline_day_offset' => 1,
            'grace_minutes' => 15,
            'weekday_mask' => 127,
        ];
    }

    private function policySnapshot(): array
    {
        $snapshot = $this->serviceSnapshot();
        foreach (['boarding_reporting_policies', 'boarding_daily_obligations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
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
