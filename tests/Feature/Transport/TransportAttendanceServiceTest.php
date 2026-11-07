<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportAttendanceService;
use App\Services\Transport\TransportExceptionService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TransportAttendanceServiceTest extends TestCase
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

        CarbonImmutable::setTestNow('2026-11-09T02:00:00Z');
        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_missed_pickup_is_non_travel_and_blocks_later_pickup(): void
    {
        $f = $this->attendanceFixture();
        CarbonImmutable::setTestNow('2026-11-09T05:00:00Z');
        $result = $this->outcome($f, 'missed_pickup');

        $this->assertSame(1, $result['attendance']['missed_pickup']);
        $this->assertSame(0, $result['attendance']['transported_legs']);
        $journey = $this->events()->show($f['actor'], $f['roster']);
        $this->assertSame('missed_pickup', $journey['state']);
        $this->assertNull($journey['custody_user_id']);
        $this->denied(fn () => $this->recordEvent(
            $f, 'pickup', $f['guardianGrant']['id']
        ), 409);
    }

    public function test_non_attendance_cannot_be_declared_before_service_window_ends(): void
    {
        $f = $this->attendanceFixture();
        $this->denied(fn () => $this->outcome($f, 'missed_pickup'), 422);
        $this->assertSame(0, DB::table('transport_journey_outcomes')
            ->where('roster_id', $f['roster'])->count());
    }

    public function test_outbound_not_boarded_is_distinct_from_inbound_missed_pickup(): void
    {
        $f = $this->attendanceFixture('from_school');
        CarbonImmutable::setTestNow('2026-11-09T05:00:00Z');
        $this->denied(fn () => $this->outcome($f, 'missed_pickup'), 422);
        $result = $this->outcome($f, 'not_boarded');
        $this->assertSame(1, $result['attendance']['not_boarded']);
        $this->assertSame(0, $result['attendance']['transported_legs']);
    }

    public function test_cancellation_is_allowed_only_before_boarding(): void
    {
        $a = $this->attendanceFixture();
        $cancelled = $this->outcome($a, 'cancelled');
        $this->assertSame(1, $cancelled['attendance']['cancelled']);
        $finished = $this->complete($a);
        $this->assertSame('completed', $finished['completion_history'][0]['status']);

        $b = $this->attendanceFixture();
        $this->recordEvent($b, 'pickup', $b['guardianGrant']['id']);
        $this->denied(fn () => $this->outcome($b, 'cancelled'), 409);
        $this->assertSame('on_board', $this->events()->show(
            $b['actor'], $b['roster']
        )['state']);
    }

    public function test_onboard_learner_keeps_completion_incomplete_until_actual_handover(): void
    {
        $f = $this->attendanceFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $first = $this->complete($f);

        $this->assertSame('incomplete', $first['completion_history'][0]['status']);
        $this->assertSame([$f['roster']], $first['attendance']['onboard_roster_ids']);

        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        $second = $this->complete($f);

        $this->assertSame($first['completion_history'][0], $second['completion_history'][0]);
        $this->assertSame('completed', $second['completion_history'][1]['status']);
        $this->assertSame(1, $second['attendance']['handed_over']);
        $this->assertSame(1, $second['attendance']['transported_legs']);
    }

    public function test_expected_learner_prevents_completed_status(): void
    {
        $f = $this->attendanceFixture();
        $result = $this->complete($f);
        $this->assertSame('incomplete', $result['completion_history'][0]['status']);
        $this->assertSame(1, $result['attendance']['awaiting_outcome']);
        $this->assertSame(0, $result['attendance']['transported_legs']);
    }

    public function test_unresolved_exception_prevents_completion_after_successful_handover(): void
    {
        $f = $this->attendanceFixture();
        $picked = $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $failed = $this->events()->record($f['actor'], $f['roster'], [
            'expected_version' => $picked['version'],
            'boundary' => 'school_arrival', 'outcome' => 'failed',
            'crew_user_id' => $f['actor']->id,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'receipt_method' => 'none', 'failure_reason' => 'person_unavailable',
            'follow_up' => 'Crew retain custody while school receiver is contacted.',
        ]);
        $arrived = $this->recordEvent(
            $f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']
        );

        $first = $this->complete($f);
        $this->assertSame('incomplete', $first['completion_history'][0]['status']);
        $this->assertSame(1, $first['attendance']['unresolved_exceptions']);

        $failure = $failed['events'][1]['id'];
        $exceptions = app(TransportExceptionService::class);
        $current = $exceptions->show($f['actor'], $failure);
        $exceptions->append($f['actor'], $failure, [
            'expected_version' => $current['version'],
            'expected_journey_version' => $current['journey_version'],
            'action' => 'resolve',
            'note' => 'School receipt recorded and exception explicitly reviewed.',
            'resolution_event_id' => $arrived['events'][2]['id'],
        ]);

        $second = $this->complete($f);
        $this->assertSame('completed', $second['completion_history'][1]['status']);
        $this->assertSame(0, $second['attendance']['unresolved_exceptions']);
        $this->assertSame(1, $first['completion_history'][0]['snapshot']['unresolved_exceptions']);
    }

    public function test_stale_completion_version_is_rejected_after_attendance_change(): void
    {
        $f = $this->attendanceFixture();
        $before = $this->attendance()->showTrip($f['actor'], $f['trip_id']);
        $this->outcome($f, 'cancelled');
        $this->denied(fn () => $this->attendance()->completeTrip(
            $f['actor'], $f['trip_id'], [
                'expected_version' => $before['version'], 'reason' => 'Stale attempt.',
            ]
        ), 409);
    }

    public function test_foreign_trip_reads_and_completion_fail_both_ways(): void
    {
        $a = $this->attendanceFixture();
        $b = $this->attendanceFixture();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $current = $this->attendance()->showTrip($foreign['actor'], $foreign['trip_id']);
            $this->denied(fn () => $this->attendance()->showTrip(
                $own['actor'], $foreign['trip_id']
            ), 404);
            $this->denied(fn () => $this->attendance()->completeTrip(
                $own['actor'], $foreign['trip_id'], [
                    'expected_version' => $current['version'], 'reason' => 'Foreign attempt.',
                ]
            ), 404);
        }
    }

    public function test_attendance_and_completion_history_are_immutable(): void
    {
        $f = $this->attendanceFixture();
        $this->outcome($f, 'cancelled');
        $completed = $this->complete($f);
        $outcome = DB::table('transport_journey_outcomes')
            ->where('roster_id', $f['roster'])->value('id');

        foreach ([
            ['transport_journey_outcomes', $outcome],
            ['transport_trip_completions', $completed['completion_history'][0]['id']],
        ] as [$table, $id]) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(function () use ($table, $id, $operation): void {
                        $query = DB::table($table)->where('id', $id);
                        if ($operation === 'update') {
                            $query->update(['reason' => 'Overwrite attempted.']);
                        } else {
                            $query->delete();
                        }
                    });
                    $this->fail('Attendance history mutation succeeded.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0] ?? null);
                }
            }
        }
    }

    public function test_audit_failure_rolls_back_outcome_and_completion_attempt(): void
    {
        $f = $this->attendanceFixture();
        $before = $this->attendance()->showTrip($f['actor'], $f['trip_id']);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Attendance audit failure.');
            });
            foreach ([
                fn () => $this->outcome($f, 'cancelled'),
                fn () => $this->complete($f),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Audit failure allowed mutation.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Attendance audit failure.', $exception->getMessage());
                }
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $this->attendance()->showTrip($f['actor'], $f['trip_id']));
        $this->assertSame('expected', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_permission_alone_does_not_allow_unassigned_staff_to_complete_trip(): void
    {
        $f = $this->attendanceFixture();
        $current = $this->attendance()->showTrip($f['actor'], $f['trip_id']);
        $this->denied(fn () => $this->attendance()->completeTrip(
            $f['receiver'], $f['trip_id'], [
                'expected_version' => $current['version'], 'reason' => 'Unassigned attempt.',
            ]
        ), 403);
    }

    private function attendance(): TransportAttendanceService
    {
        return app(TransportAttendanceService::class);
    }

    private function attendanceFixture(string $direction = 'to_school'): array
    {
        $f = $this->journey($direction);
        $trip = DB::table('transport_trip_roster')->where('id', $f['roster'])->value('trip_id');

        return [...$f, 'trip_id' => $trip];
    }

    private function outcome(array $f, string $outcome): array
    {
        $journey = $this->events()->show($f['actor'], $f['roster']);

        return $this->attendance()->recordOutcome($f['actor'], $f['roster'], [
            'expected_version' => $journey['version'],
            'outcome' => $outcome,
            'reason' => 'Explicit dated journey attendance outcome.',
        ]);
    }

    private function complete(array $f): array
    {
        $current = $this->attendance()->showTrip($f['actor'], $f['trip_id']);

        return $this->attendance()->completeTrip($f['actor'], $f['trip_id'], [
            'expected_version' => $current['version'],
            'reason' => 'Crew end-of-run reconciliation.',
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
