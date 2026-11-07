<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportReportingSchedulerService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportReportingSchedulerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Transport database required.');
        }

        config(['transport_reporting.scheduler_enabled' => false, 'transport_reporting.scheduler_actors' => []]);
        CarbonImmutable::setTestNow('2026-11-09T02:00:00Z');
        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_disabled_scheduler_and_command_do_not_select_an_actor_or_write(): void
    {
        $f = $this->schedulerFixture();
        config(['transport_reporting.scheduler_enabled' => false, 'transport_reporting.scheduler_actors' => 'invalid while disabled']);
        $before = $this->schedulerCounts($f);
        $result = $this->scheduler()->run();
        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['schools']);
        $this->artisan('transport:materialize-reporting-obligations')->assertExitCode(0);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_enabled_scheduler_without_mappings_does_not_choose_school_users(): void
    {
        $f = $this->schedulerFixture();
        config(['transport_reporting.scheduler_enabled' => true, 'transport_reporting.scheduler_actors' => []]);
        $before = $this->schedulerCounts($f);
        $result = $this->scheduler()->run();
        $this->assertTrue($result['enabled']);
        $this->assertSame([], $result['schools']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_repeated_execution_creates_one_obligation_and_one_strict_audit(): void
    {
        $f = $this->schedulerFixture();
        $this->configureScheduler($f);
        $first = $this->scheduler()->run();
        $second = $this->scheduler()->run();
        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $first['existing']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['existing']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame(['2026-11-09'], $first['schools'][0]['reporting_dates']);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->count());
        $this->assertSame($f['actor']->id, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->value('materialized_by'));
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school']->id)->where('table_name', 'transport_reporting_obligations')->count());
    }

    public function test_service_without_runs_or_eligible_reporters_still_receives_an_obligation(): void
    {
        $f = $this->fixture();
        $policy = $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
        $this->configureScheduler($f);
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['created']);
        $id = DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->where('policy_id', $policy['id'])->value('id');
        $this->assertNotNull($id);
        $view = $this->reporting()->show($f['actor'], $id);
        $this->assertSame('unassigned', $view['responsibility_state']);
        $this->assertSame(0, $view['totals']['planned_runs']);
        $this->assertSame(0, DB::table('transport_daily_submissions')->where('school_id', $f['school']->id)->count());
    }

    public function test_each_policy_uses_current_service_local_date_without_historical_backfill(): void
    {
        $f = $this->fixture();
        $nairobi = $this->policy($f);
        $losAngeles = $this->policy($f, ['schedule_id' => $this->emptySchedule($f, ['timezone' => 'America/Los_Angeles'])]);
        $this->configureScheduler($f);
        CarbonImmutable::setTestNow('2026-11-09T22:00:00Z');
        $result = $this->scheduler()->run();
        $this->assertSame(2, $result['created']);
        $this->assertSame(['2026-11-09', '2026-11-10'], $result['schools'][0]['reporting_dates']);
        $this->assertDatabaseHas('transport_reporting_obligations', [
            'school_id' => $f['school']->id, 'policy_id' => $nairobi['id'], 'service_date' => '2026-11-10',
        ]);
        $this->assertDatabaseHas('transport_reporting_obligations', [
            'school_id' => $f['school']->id, 'policy_id' => $losAngeles['id'], 'service_date' => '2026-11-09',
        ]);
        $this->assertDatabaseMissing('transport_reporting_obligations', [
            'school_id' => $f['school']->id, 'policy_id' => $nairobi['id'], 'service_date' => '2026-11-09',
        ]);
        $this->assertSame(2, $this->scheduler()->run()['existing']);
    }

    public function test_future_policies_non_service_days_and_retired_schedules_are_skipped(): void
    {
        $f = $this->schedulerFixture();
        $this->policy($f, ['schedule_id' => $this->emptySchedule($f), 'weekday_mask' => 2]);
        $this->policy($f, ['schedule_id' => $this->emptySchedule($f), 'effective_from' => '2026-11-10']);
        $retired = $this->emptySchedule($f);
        $this->policy($f, ['schedule_id' => $retired]);
        DB::table('transport_service_schedules')->where('id', $retired)->update(['active' => false]);
        $this->configureScheduler($f);
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->count());
    }

    public function test_all_mapping_shapes_are_validated_before_the_first_school_write(): void
    {
        $f = $this->schedulerFixture();
        $before = $this->schedulerCounts($f);
        $duplicate = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        foreach ([
            null, 'invalid', [0 => $f['actor']->id],
            [$f['school']->id => 'not-a-user-uuid'],
            [$f['school']->id => $f['actor']->id, 'invalid-school' => $f['actor']->id],
            [$f['school']->id => $f['actor']->id, $duplicate => $f['actor']->id, strtoupper($duplicate) => $f['actor']->id],
            array_fill(0, 1001, $f['actor']->id),
        ] as $mapping) {
            config(['transport_reporting.scheduler_enabled' => true, 'transport_reporting.scheduler_actors' => $mapping]);
            try {
                $this->scheduler()->run();
                $this->fail('Invalid scheduler mapping was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Transport', $exception->getMessage());
            }
            $this->assertSame($before, $this->schedulerCounts($f));
        }
    }

    public function test_foreign_actor_mapping_fails_without_mutating_either_school(): void
    {
        $a = $this->schedulerFixture();
        $b = $this->schedulerFixture();
        config([
            'transport_reporting.scheduler_enabled' => true,
            'transport_reporting.scheduler_actors' => [$a['school']->id => $b['actor']->id],
        ]);
        $beforeA = $this->schedulerCounts($a);
        $beforeB = $this->schedulerCounts($b);
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['failed']);
        $this->assertSame('access_unavailable', $result['schools'][0]['status']);
        $this->assertSame($beforeA, $this->schedulerCounts($a));
        $this->assertSame($beforeB, $this->schedulerCounts($b));
        $this->artisan('transport:materialize-reporting-obligations')->assertExitCode(1);
    }

    public function test_permission_revocation_is_rechecked_before_materialization(): void
    {
        $f = $this->schedulerFixture();
        $this->configureScheduler($f);
        $permission = DB::table('permissions')->where('permission_name', 'manage_transport')->value('id');
        DB::table('role_permissions')->where('role_id', $f['actor']->role_id)->where('permission_id', $permission)->delete();
        $before = $this->schedulerCounts($f);
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['failed']);
        $this->assertSame('access_unavailable', $result['schools'][0]['status']);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_current_user_school_and_setup_gates_are_rechecked(): void
    {
        $f = $this->schedulerFixture();
        $this->configureScheduler($f);
        $before = $this->schedulerCounts($f);
        foreach ([
            ['active' => false], ['is_deleted' => true],
            ['suspended_at' => '2026-11-09 01:00:00+00'],
            ['account_locked_until' => '2026-11-10 01:00:00+00'],
        ] as $attributes) {
            DB::table('users')->where('id', $f['actor']->id)->update([
                'active' => true, 'is_deleted' => false, 'suspended_at' => null,
                'account_locked_until' => null, ...$attributes,
            ]);
            $this->assertSame(1, $this->scheduler()->run()['failed']);
            $this->assertSame($before, $this->schedulerCounts($f));
        }
        DB::table('users')->where('id', $f['actor']->id)->update([
            'active' => true, 'is_deleted' => false, 'suspended_at' => null, 'account_locked_until' => null,
        ]);
        foreach ([['lifecycle_state' => 'read_only'], ['operating_mode' => 'boarding']] as $attributes) {
            DB::table('schools')->where('id', $f['school']->id)->update([
                'lifecycle_state' => 'active', 'operating_mode' => 'day', ...$attributes,
            ]);
            $this->assertSame(1, $this->scheduler()->run()['failed']);
            $this->assertSame($before, $this->schedulerCounts($f));
        }
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'active', 'operating_mode' => 'day']);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(false);
        $this->assertSame(1, $this->scheduler()->run()['failed']);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_audit_failure_rolls_back_every_obligation_for_the_school(): void
    {
        $f = $this->schedulerFixture();
        $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
        $this->configureScheduler($f);
        $before = $this->schedulerCounts($f);
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        $attempts = 0;
        try {
            AuditLog::creating(static function (AuditLog $audit) use (&$attempts): void {
                if ($audit->table_name === 'transport_reporting_obligations' && ++$attempts === 2) {
                    throw new \RuntimeException('Second obligation strict audit failed.');
                }
            });
            try {
                $this->scheduler()->run();
                $this->fail('Strict audit failure was hidden.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Second obligation strict audit failed.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame(2, $attempts);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_rejected_policy_rolls_back_other_obligations_for_the_same_school(): void
    {
        $f = $this->schedulerFixture();
        $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
        $this->configureScheduler($f);
        $before = $this->schedulerCounts($f);
        $this->mock(TransportReportingObligationService::class)
            ->shouldReceive('materialize')->andThrow(new HttpException(422, 'Rejected policy fixture.'));
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['failed']);
        $this->assertSame('materialization_rejected', $result['schools'][0]['status']);
        $this->assertSame($before, $this->schedulerCounts($f));
    }

    public function test_schools_commit_independently_and_access_failure_is_not_silenced(): void
    {
        $a = $this->schedulerFixture();
        $b = $this->schedulerFixture();
        DB::table('schools')->where('id', $b['school']->id)->update(['lifecycle_state' => 'read_only']);
        config([
            'transport_reporting.scheduler_enabled' => true,
            'transport_reporting.scheduler_actors' => [$a['school']->id => $a['actor']->id, $b['school']->id => $b['actor']->id],
        ]);
        $result = $this->scheduler()->run();
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $a['school']->id)->count());
        $this->assertSame(0, DB::table('transport_reporting_obligations')->where('school_id', $b['school']->id)->count());
    }

    public function test_command_and_schedule_remain_disabled_without_explicit_boolean_enablement(): void
    {
        $f = $this->schedulerFixture();
        $this->configureScheduler($f);
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event): bool => str_contains($event->command ?? '', 'transport:materialize-reporting-obligations')
        );
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        foreach ([false, 'false', 'true', 1] as $enabled) {
            config(['transport_reporting.scheduler_enabled' => $enabled]);
            $this->assertFalse($this->scheduler()->run()['enabled']);
            $this->assertFalse($event->filtersPass(app()));
        }
        $this->assertSame(0, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->count());
        config(['transport_reporting.scheduler_enabled' => true]);
        $this->assertTrue($event->filtersPass(app()));
        $this->artisan('transport:materialize-reporting-obligations')->assertExitCode(0);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->count());
    }

    private function scheduler(): TransportReportingSchedulerService
    {
        return app(TransportReportingSchedulerService::class);
    }

    private function schedulerFixture(): array
    {
        $f = $this->fixture();
        $f['policy'] = $this->policy($f);

        return $f;
    }

    private function configureScheduler(array $f): void
    {
        config([
            'transport_reporting.scheduler_enabled' => true,
            'transport_reporting.scheduler_actors' => [$f['school']->id => $f['actor']->id],
        ]);
    }

    private function schedulerCounts(array $f): array
    {
        $result = [];
        foreach (['transport_reporting_obligations', 'transport_daily_submissions', 'audit_logs'] as $table) {
            $result[$table] = DB::table($table)->where('school_id', $f['school']->id)->count();
        }

        return $result;
    }

    private function emptySchedule(array $f, array $extra = []): string
    {
        $original = DB::table('transport_dated_assignments')->where('id', $f['assignment']['id'])->value('schedule_id');
        $row = DB::table('transport_service_schedules')->where('id', $original)->first();
        $id = (string) Str::uuid();
        DB::table('transport_service_schedules')->insert([
            'id' => $id, 'school_id' => $f['school']->id, 'route_id' => $row->route_id,
            'service_code' => 'VIEW-'.Str::random(12), 'direction' => $row->direction,
            'timezone' => $row->timezone, 'weekday_mask' => $row->weekday_mask,
            'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to,
            'departure_time' => $row->departure_time, 'completion_time' => $row->completion_time,
            'completion_day_offset' => $row->completion_day_offset,
            'created_by' => $f['actor']->id, 'updated_by' => $f['actor']->id, ...$extra,
        ]);

        return $id;
    }

    private function daily(): TransportDailyReportService
    {
        return app(TransportDailyReportService::class);
    }

    private function grant(User $actor, string $name): void
    {
        $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($permission);
        if (! DB::table('role_permissions')->where('role_id', $actor->role_id)->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $actor->role_id, 'permission_id' => $permission,
            ]);
        }
    }

    private function dailyFixture(): array
    {
        $f = $this->journey();
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        $f['obligation'] = $this->reporting()->materialize($f['actor'], $this->policy($f)['id'])['id'];

        return $f;
    }

    private function reviewer(array $f): User
    {
        $builder = UserBuilder::create($f['school'], RoleBuilder::create());
        $user = User::withoutGlobalScopes()->findOrFail($builder->id);
        $this->grant($user, 'view_transport_operations');
        $this->grant($user, 'review_transport_daily_reports');

        return $user;
    }

    private function submitDaily(array $f): array
    {
        $before = $this->daily()->show($f['actor'], $f['obligation']);

        return $this->daily()->submit($f['actor'], $f['obligation'], [
            'expected_version' => $before['version'],
            'summary' => 'End of service account based on recorded evidence.',
            'exception_note' => 'Outstanding operational and custody issues remain under active follow-up.',
        ]);
    }

    private function reporting(): TransportReportingObligationService
    {
        return app(TransportReportingObligationService::class);
    }

    private function policy(array $f, array $extra = []): array
    {
        $schedule = DB::table('transport_dated_assignments')
            ->where('id', $f['assignment']['id'])->value('schedule_id');

        return $this->reporting()->createPolicy($f['actor'], [
            'schedule_id' => $schedule,
            'effective_from' => '2026-11-09', 'effective_to' => '2026-11-30',
            'weekday_mask' => 127,
            'deadline_time' => '17:00', 'deadline_day_offset' => 0,
            'grace_minutes' => 30,
            ...$extra,
        ]);
    }

    private function events(): TransportJourneyEventService
    {
        return app(TransportJourneyEventService::class);
    }

    private function input(array $f, string $boundary, string $authorization): array
    {
        $current = $this->events()->show($f['actor'], $f['roster']);

        return [
            'expected_version' => $current['version'],
            'boundary' => $boundary, 'outcome' => 'recorded',
            'crew_user_id' => $f['actor']->id,
            'authorization_id' => $authorization,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'verification_method' => 'known_person_in_person',
            'verification_note' => 'Actual handover counterpart verified in person.',
            'receipt_method' => 'authenticated_receiver',
        ];
    }

    private function recordEvent(
        array $f,
        string $boundary,
        string $authorization,
        ?User $actor = null,
        string $receipt = 'authenticated_receiver'
    ): array {
        return $this->events()->record($actor ?? $f['actor'], $f['roster'], [
            ...$this->input($f, $boundary, $authorization),
            'receipt_method' => $receipt,
        ]);
    }

    private function journey(string $direction = 'to_school'): array
    {
        $f = $this->fixture($direction);
        CarbonImmutable::setTestNow('2026-11-09T04:30:00Z');

        $permission = DB::table('permissions')
            ->where('permission_name', 'authorize_transport_handover')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(), 'role_id' => $f['actor']->role_id,
            'permission_id' => $permission,
        ]);

        $guardian = (string) Str::uuid();
        $link = (string) Str::uuid();
        DB::table('parents')->insert([
            'id' => $guardian, 'school_id' => $f['school']->id,
            'first_name' => 'Approved', 'last_name' => 'Guardian',
            'phone' => '+254700000001', 'active' => true, 'is_deleted' => false,
        ]);
        DB::table('learner_parents')->insert([
            'id' => $link, 'learner_id' => $f['learner']->id,
            'parent_id' => $guardian, 'relationship' => 'Parent',
            'active' => true, 'is_deleted' => false,
            'can_pick_learner' => true, 'portal_enabled' => false,
        ]);

        $authorizations = app(
            TransportHandoverAuthorizationService::class
        );
        $guardianGrant = $authorizations->create($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
            'kind' => 'guardian_recipient',
            'learner_id' => $f['learner']->id, 'stop_id' => $f['stop'],
            'guardian_id' => $guardian, 'guardian_link_id' => $link,
            'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Approved concrete-run handover arrangement.',
        ]);
        $receiver = User::withoutGlobalScopes()->findOrFail($f['unassigned']->id);
        $schoolGrant = $authorizations->create($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
            'kind' => $direction === 'to_school' ? 'school_receiver' : 'school_releaser',
            'staff_user_id' => $receiver->id,
            'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Assigned school handover counterpart.',
        ]);
        $trip = app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]);
        $roster = $trip['roster'][0]['id'];

        return [...$f, ...compact(
            'guardian', 'link', 'guardianGrant', 'schoolGrant', 'receiver', 'roster'
        )];
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid journey operation succeeded.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $status);
            $this->assertNotEmpty($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function fixture(string $direction = 'to_school'): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id);
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($user->id);
        $unassigned = UserBuilder::create($school, $role);
        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $learner = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);
        $other = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);

        foreach ([
            'manage_transport', 'manage_transport_enrolments',
            'view_transport_operations', 'record_transport_journeys',
        ] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $role->id,
                'permission_id' => $permission,
            ]);
        }

        $route = (string) Str::uuid();
        DB::table('transport_routes')->insert([
            'id' => $route, 'school_id' => $school->id,
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Original route',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $stop = (string) Str::uuid();
        DB::table('transport_route_stops')->insert([
            'id' => $stop, 'school_id' => $school->id, 'route_id' => $route,
            'direction' => $direction, 'stop_name' => 'Original stop', 'stop_order' => 1,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $vehicle = (string) Str::uuid();
        DB::table('transport_vehicles')->insert([
            'id' => $vehicle, 'school_id' => $school->id,
            'registration_number' => 'BUS-'.Str::random(12),
            'ownership_type' => 'school_owned', 'learner_capacity' => 2,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $schedule = (string) Str::uuid();
        DB::table('transport_service_schedules')->insert([
            'id' => $schedule, 'school_id' => $school->id, 'route_id' => $route,
            'service_code' => 'S-'.Str::random(12), 'direction' => $direction,
            'timezone' => 'Africa/Nairobi', 'weekday_mask' => 127,
            'effective_from' => '2026-11-01', 'effective_to' => '2026-12-31',
            'departure_time' => '07:00', 'completion_time' => '08:00',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $assignment = app(TransportAssignmentService::class)->create($actor, [
            'schedule_id' => $schedule, 'service_date' => '2026-11-09',
            'vehicle_id' => $vehicle, 'driver_id' => $actor->id,
        ]);
        $reservation = app(TransportLearnerReservationService::class)->create($actor, [
            'assignment_id' => $assignment['id'],
            'learner_id' => $learner->id, 'stop_id' => $stop,
        ]);

        return compact(
            'school', 'actor', 'unassigned', 'learner', 'other',
            'route', 'stop', 'vehicle', 'assignment', 'reservation'
        );
    }
}
