<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
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

class TransportExceptionServiceTest extends TestCase
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

    public function test_follow_up_preserves_failure_and_transport_custody(): void
    {
        $f = $this->failedJourney();
        $original = DB::table('transport_journey_events')->where('id', $f['failure'])->first();

        $after = $this->appendAction($f, 'follow_up');
        $this->assertSame('open', $after['status']);
        $this->assertCount(1, $after['history']);
        $this->assertSame('on_board', $after['current_custody']['state']);
        $this->assertSame($f['actor']->id, $after['current_custody']['custody_user_id']);
        $this->assertEquals($original, DB::table('transport_journey_events')
            ->where('id', $f['failure'])->first());
    }

    public function test_authorized_reassignment_preserves_previous_owner_history(): void
    {
        $f = $this->failedJourney();
        $first = $this->appendAction($f, 'follow_up');
        $next = $this->appendAction($f, 'follow_up', [
            'assigned_to' => $f['receiver']->id,
        ]);

        $this->assertSame($f['receiver']->id, $next['assigned_to']);
        $this->assertSame($f['actor']->id, $next['history'][0]['assigned_to']);
        $this->assertSame($f['receiver']->id, $next['history'][1]['assigned_to']);
        $this->assertNotSame($first['version'], $next['version']);
    }

    public function test_resolution_requires_later_success_at_the_same_boundary(): void
    {
        $f = $this->failedJourney();
        $departure = DB::table('transport_journey_events')
            ->where('roster_id', $f['roster'])->where('boundary', 'school_departure')
            ->value('id');

        $this->denied(fn () => $this->appendAction($f, 'resolve', [
            'resolution_event_id' => $departure,
        ]), 422);

        $this->assertSame('open', $this->exceptions()->show(
            $f['actor'], $f['failure']
        )['status']);
    }

    public function test_explicit_resolution_keeps_original_failure_and_records_receipt_quality(): void
    {
        $f = $this->failedJourney();
        $completed = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $proof = $completed['events'][2]['id'];
        $this->assertContains($f['failure'], $completed['unresolved_failure_ids']);

        $resolved = $this->appendAction($f, 'resolve', [
            'resolution_event_id' => $proof,
        ]);

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('verbally_reported',
            $resolved['history'][0]['custody']['resolution_receipt_state']);
        $this->assertSame($proof, $resolved['history'][0]['resolution_event_id']);
        $this->assertSame('person_unavailable', $resolved['failure_reason']);
        $this->assertDatabaseHas('transport_journey_events', [
            'id' => $f['failure'], 'outcome' => 'failed',
        ]);
        $this->assertNotContains($f['failure'], $this->events()->show(
            $f['actor'], $f['roster']
        )['unresolved_failure_ids']);
    }

    public function test_stale_exception_and_changed_journey_versions_are_rejected(): void
    {
        $f = $this->failedJourney();
        $old = $this->exceptions()->show($f['actor'], $f['failure']);
        $this->appendAction($f, 'follow_up');

        $this->denied(fn () => $this->exceptions()->append($f['actor'], $f['failure'], [
            'expected_version' => $old['version'],
            'expected_journey_version' => $old['journey_version'],
            'action' => 'follow_up', 'note' => 'Stale follow-up.',
        ]), 409);

        $beforeJourneyChange = $this->exceptions()->show($f['actor'], $f['failure']);
        $completed = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $this->denied(fn () => $this->exceptions()->append($f['actor'], $f['failure'], [
            'expected_version' => $beforeJourneyChange['version'],
            'expected_journey_version' => $beforeJourneyChange['journey_version'],
            'action' => 'resolve', 'note' => 'Stale custody view.',
            'resolution_event_id' => $completed['events'][2]['id'],
        ]), 409);
    }

    public function test_crew_can_follow_up_but_cannot_resolve_without_authorization_permission(): void
    {
        $f = $this->failedJourney();
        $permission = DB::table('permissions')
            ->where('permission_name', 'authorize_transport_handover')->value('id');
        DB::table('role_permissions')->where('role_id', $f['actor']->role_id)
            ->where('permission_id', $permission)->delete();

        $this->assertSame('open', $this->appendAction($f, 'follow_up')['status']);
        $this->denied(fn () => $this->appendAction($f, 'resolve', [
            'resolution_event_id' => $f['failure'],
        ]), 403);
        $this->denied(fn () => $this->appendAction($f, 'follow_up', [
            'assigned_to' => $f['receiver']->id,
        ]), 403);
    }

    public function test_foreign_exception_reads_and_mutations_fail_both_ways(): void
    {
        $a = $this->failedJourney();
        $b = $this->failedJourney();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->denied(fn () => $this->exceptions()->show(
                $own['actor'], $foreign['failure']
            ), 404);
            $current = $this->exceptions()->show($foreign['actor'], $foreign['failure']);
            $this->denied(fn () => $this->exceptions()->append($own['actor'], $foreign['failure'], [
                'expected_version' => $current['version'],
                'expected_journey_version' => $current['journey_version'],
                'action' => 'follow_up', 'note' => 'Foreign attempt.',
            ]), 404);
        }
    }

    public function test_follow_up_rows_cannot_be_updated_or_deleted(): void
    {
        $f = $this->failedJourney();
        $result = $this->appendAction($f, 'follow_up');
        $id = $result['history'][0]['id'];

        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($id, $operation): void {
                    $query = DB::table('transport_exception_follow_ups')->where('id', $id);
                    if ($operation === 'update') {
                        $query->update(['note' => 'Overwrite attempted.']);
                    } else {
                        $query->delete();
                    }
                });
                $this->fail('Exception history mutation succeeded.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0] ?? null);
            }
        }
    }

    public function test_audit_failure_rolls_back_follow_up_and_resolution(): void
    {
        $f = $this->failedJourney();
        $completed = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $before = $this->exceptions()->show($f['actor'], $f['failure']);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Exception audit failure.');
            });

            foreach ([
                fn () => $this->appendAction($f, 'follow_up'),
                fn () => $this->appendAction($f, 'resolve', [
                    'resolution_event_id' => $completed['events'][2]['id'],
                ]),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Audit failure allowed exception mutation.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Exception audit failure.', $exception->getMessage());
                }
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $this->exceptions()->show($f['actor'], $f['failure']));
        $this->assertContains($f['failure'], $this->events()->show(
            $f['actor'], $f['roster']
        )['unresolved_failure_ids']);
    }

    public function test_resolved_exception_cannot_be_extended_or_resolved_again(): void
    {
        $f = $this->failedJourney();
        $completed = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $proof = $completed['events'][2]['id'];
        $this->appendAction($f, 'resolve', ['resolution_event_id' => $proof]);

        $this->denied(fn () => $this->appendAction($f, 'follow_up'), 409);
        $this->denied(fn () => $this->appendAction($f, 'resolve', [
            'resolution_event_id' => $proof,
        ]), 409);
    }

    private function exceptions(): TransportExceptionService
    {
        return app(TransportExceptionService::class);
    }

    private function appendAction(array $f, string $action, array $extra = []): array
    {
        $current = $this->exceptions()->show($f['actor'], $f['failure']);

        return $this->exceptions()->append($f['actor'], $f['failure'], [
            'expected_version' => $current['version'],
            'expected_journey_version' => $current['journey_version'],
            'action' => $action,
            'note' => 'Recorded custody follow-up and supporting action.',
            ...$extra,
        ]);
    }

    private function failedJourney(): array
    {
        $f = $this->journey('from_school');
        $departed = $this->recordEvent($f, 'school_departure', $f['schoolGrant']['id']);
        $failed = $this->events()->record($f['actor'], $f['roster'], [
            'expected_version' => $departed['version'],
            'boundary' => 'destination_dropoff', 'outcome' => 'failed',
            'crew_user_id' => $f['actor']->id,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'receipt_method' => 'none',
            'failure_reason' => 'person_unavailable',
            'follow_up' => 'Assigned crew retain custody and contact school leadership.',
        ]);

        return [...$f, 'failure' => $failed['events'][1]['id']];
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
