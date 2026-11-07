<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

abstract class TransportHttpTestCase extends TestCase
{
    use DatabaseTransactions;

    protected ?string $transportAddonEndsAt = null;

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

        config(['jwt.secret' => str_repeat('transport-http-test-secret-', 3), 'jwt.ttl' => 60,
            'transport_reporting.scheduler_enabled' => false, 'boarding_reporting.scheduler_enabled' => false]);
        CarbonImmutable::setTestNow('2026-11-09T02:00:00Z');
        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function httpAs(User $user): void
    {
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    protected function removePermission(User $user, string $name): void
    {
        $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
        DB::table('role_permissions')->where('role_id', $user->role_id)->where('permission_id', $permission)->delete();
    }

    protected function httpSnapshot(array $f): array
    {
        $result = [];
        foreach ([
            'transport_vehicles', 'transport_routes', 'transport_route_stops', 'transport_service_schedules',
            'transport_dated_assignments', 'transport_staff_reservations', 'transport_learner_reservations',
            'transport_enrolment_episodes', 'transport_enrolment_reservations', 'transport_enrolment_transfers',
            'transport_trips', 'transport_trip_roster', 'transport_handover_authorizations', 'transport_handover_revocations',
            'transport_journey_events', 'transport_journey_outcomes', 'transport_trip_completions', 'transport_exception_follow_ups',
            'transport_reporting_policies', 'transport_reporting_obligations', 'transport_daily_submissions', 'transport_daily_reviews', 'audit_logs',
        ] as $table) {
            $result[$table] = DB::table($table)->where('school_id', $f['school']->id)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    protected function scheduleInput(array $f, array $extra = []): array
    {
        return [
            'route_id' => $f['route'], 'service_code' => 'HTTP-'.Str::random(12),
            'direction' => 'to_school', 'timezone' => 'Africa/Nairobi', 'weekday_mask' => 127,
            'effective_from' => '2026-11-09', 'effective_to' => '2026-11-30',
            'departure_time' => '07:00', 'completion_time' => '08:00',
            'completion_day_offset' => 0, 'turnaround_minutes' => 15, ...$extra,
        ];
    }

    protected function tripId(array $f): string
    {
        return (string) DB::table('transport_trip_roster')->where('id', $f['roster'])->value('trip_id');
    }

    protected function daily(): TransportDailyReportService
    {
        return app(TransportDailyReportService::class);
    }

    protected function grant(User $actor, string $name): void
    {
        $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($permission);
        if (! DB::table('role_permissions')->where('role_id', $actor->role_id)->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $actor->role_id, 'permission_id' => $permission,
            ]);
        }
    }

    protected function dailyFixture(): array
    {
        $f = $this->journey();
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        $f['obligation'] = $this->reporting()->materialize($f['actor'], $this->policy($f)['id'])['id'];

        return $f;
    }

    protected function reviewer(array $f): User
    {
        $builder = UserBuilder::create($f['school'], RoleBuilder::create());
        $user = User::withoutGlobalScopes()->findOrFail($builder->id);
        $this->grant($user, 'view_transport_operations');
        $this->grant($user, 'review_transport_daily_reports');

        return $user;
    }

    protected function submitDaily(array $f): array
    {
        $before = $this->daily()->show($f['actor'], $f['obligation']);

        return $this->daily()->submit($f['actor'], $f['obligation'], [
            'expected_version' => $before['version'],
            'summary' => 'End of service account based on recorded evidence.',
            'exception_note' => 'Outstanding operational and custody issues remain under active follow-up.',
        ]);
    }

    protected function reporting(): TransportReportingObligationService
    {
        return app(TransportReportingObligationService::class);
    }

    protected function policy(array $f, array $extra = []): array
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

    protected function events(): TransportJourneyEventService
    {
        return app(TransportJourneyEventService::class);
    }

    protected function input(array $f, string $boundary, string $authorization): array
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

    protected function recordEvent(
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

    protected function journey(string $direction = 'to_school'): array
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

    protected function denied(callable $operation, int $status): void
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

    protected function fixture(string $direction = 'to_school'): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id, $this->transportAddonEndsAt);
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
