<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
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

class TransportJourneyEventServiceTest extends TestCase
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

    public function test_inbound_events_preserve_actual_people_and_authenticated_school_receipt(): void
    {
        $f = $this->journey();
        $picked = $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->assertSame('on_board', $picked['state']);
        $this->assertSame($f['actor']->id, $picked['custody_user_id']);
        $this->assertSame($f['guardian'], $picked['events'][0]['from_person']['id']);

        $arrived = $this->recordEvent(
            $f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']
        );
        $this->assertSame('at_school', $arrived['state']);
        $this->assertSame($f['receiver']->id, $arrived['custody_user_id']);
        $this->assertSame($f['actor']->id, $arrived['events'][1]['from_person']['id']);
        $this->assertSame($f['receiver']->id, $arrived['events'][1]['to_person']['id']);
        $this->assertSame($f['receiver']->id, $arrived['events'][1]['confirmed_by']);

        DB::table('parents')->where('id', $f['guardian'])->update(['first_name' => 'Changed']);
        $this->assertSame($arrived, $this->events()->show($f['actor'], $f['roster']));
    }

    public function test_outbound_departure_and_destination_verbal_receipt_are_distinct(): void
    {
        $f = $this->journey('from_school');
        $departed = $this->recordEvent($f, 'school_departure', $f['schoolGrant']['id']);
        $this->assertSame('on_board', $departed['state']);
        $this->assertSame($f['receiver']->id, $departed['events'][0]['from_person']['id']);

        $dropped = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $this->assertSame('at_destination', $dropped['state']);
        $this->assertSame($f['guardian'], $dropped['custody_guardian_id']);
        $this->assertSame('verbally_reported', $dropped['events'][1]['receipt_state']);
        $this->assertNull($dropped['events'][1]['confirmed_by']);
    }

    public function test_failed_dropoff_preserves_transport_custody_and_remains_visible_after_success(): void
    {
        $f = $this->journey('from_school');
        $this->recordEvent($f, 'school_departure', $f['schoolGrant']['id']);
        $before = $this->events()->show($f['actor'], $f['roster']);

        $failed = $this->events()->record($f['actor'], $f['roster'], [
            'expected_version' => $before['version'],
            'boundary' => 'destination_dropoff', 'outcome' => 'failed',
            'crew_user_id' => $f['actor']->id,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'receipt_method' => 'none', 'failure_reason' => 'person_unavailable',
            'follow_up' => 'Learner remains with assigned crew; contact school leadership.',
        ]);

        $this->assertSame('on_board', $failed['state']);
        $this->assertSame($f['actor']->id, $failed['custody_user_id']);
        $this->assertNull($failed['custody_guardian_id']);
        $this->assertCount(1, $failed['unresolved_failure_ids']);
        $this->assertSame($f['actor']->id, $failed['events'][1]['exception_owner_id']);

        $completed = $this->recordEvent(
            $f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal'
        );
        $this->assertSame('at_destination', $completed['state']);
        $this->assertSame($failed['unresolved_failure_ids'], $completed['unresolved_failure_ids']);
    }

    public function test_stale_and_duplicate_transitions_do_not_append_evidence(): void
    {
        $f = $this->journey();
        $old = $this->events()->show($f['actor'], $f['roster']);
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);

        $input = $this->input($f, 'pickup', $f['guardianGrant']['id']);
        $this->denied(fn () => $this->events()->record($f['actor'], $f['roster'], [
            ...$input, 'expected_version' => $old['version'],
        ]), 409);
        $this->denied(fn () => $this->events()->record(
            $f['actor'], $f['roster'], $input
        ), 409);
        $this->assertSame(1, DB::table('transport_journey_events')
            ->where('roster_id', $f['roster'])->count());
    }

    public function test_revocation_and_current_guardian_link_block_success(): void
    {
        $f = $this->journey();
        DB::table('learner_parents')->where('id', $f['link'])
            ->update(['can_pick_learner' => false]);
        $this->denied(fn () => $this->recordEvent(
            $f, 'pickup', $f['guardianGrant']['id']
        ), 422);

        DB::table('learner_parents')->where('id', $f['link'])
            ->update(['can_pick_learner' => true]);
        app(TransportHandoverAuthorizationService::class)->revoke(
            $f['actor'], $f['guardianGrant']['id'], [
                'expected_version' => $f['guardianGrant']['version'],
                'reason' => 'Collection authority withdrawn.',
            ]
        );
        $this->denied(fn () => $this->recordEvent(
            $f, 'pickup', $f['guardianGrant']['id']
        ), 422);
        $this->assertSame(0, DB::table('transport_journey_events')
            ->where('roster_id', $f['roster'])->count());
    }

    public function test_permission_alone_does_not_establish_trip_responsibility(): void
    {
        $f = $this->journey();
        $input = $this->input($f, 'pickup', $f['guardianGrant']['id']);
        $this->denied(fn () => $this->events()->record(
            $f['receiver'], $f['roster'], $input
        ), 403);
    }

    public function test_recorder_cannot_fabricate_another_users_authenticated_receipt(): void
    {
        $f = $this->journey();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->denied(fn () => $this->recordEvent(
            $f, 'school_arrival', $f['schoolGrant']['id']
        ), 422);
    }

    public function test_foreign_rosters_fail_in_both_directions(): void
    {
        $a = $this->journey();
        $b = $this->journey();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->denied(fn () => $this->events()->show(
                $own['actor'], $foreign['roster']
            ), 404);
            $this->denied(fn () => $this->events()->record(
                $own['actor'], $foreign['roster'],
                $this->input($own, 'pickup', $own['guardianGrant']['id'])
            ), 404);
        }
    }

    public function test_event_evidence_cannot_be_updated_or_deleted(): void
    {
        $f = $this->journey();
        $result = $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $id = $result['events'][0]['id'];

        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($id, $operation): void {
                    $query = DB::table('transport_journey_events')->where('id', $id);
                    if ($operation === 'update') {
                        $query->update(['verification_note' => 'Overwrite attempted.']);
                    } else {
                        $query->delete();
                    }
                });
                $this->fail('Security evidence mutation succeeded.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0] ?? null);
            }
        }
    }

    public function test_audit_failure_rolls_back_event_and_journey_version(): void
    {
        $f = $this->journey();
        $before = $this->events()->show($f['actor'], $f['roster']);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Journey audit failure.');
            });
            try {
                $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
                $this->fail('Audit failure allowed event recording.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Journey audit failure.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $this->events()->show($f['actor'], $f['roster']));
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
