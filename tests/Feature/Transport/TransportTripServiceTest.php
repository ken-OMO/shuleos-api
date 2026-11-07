<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TransportTripServiceTest extends TestCase
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

    public function test_capture_freezes_context_and_repeated_capture_is_idempotent(): void
    {
        $f = $this->fixture();
        $service = app(TransportTripService::class);
        $trip = $service->capture($f['actor'], ['assignment_id' => $f['assignment']['id']]);

        $this->assertSame(1, $trip['roster_count']);
        $this->assertCount(1, $trip['roster']);
        $this->assertSame('Original stop', $trip['roster'][0]['stop']['stop_name']);

        DB::table('transport_route_stops')->where('id', $f['stop'])
            ->update(['stop_name' => 'Changed stop']);
        DB::table('transport_routes')->where('id', $f['route'])
            ->update(['route_name' => 'Changed route']);
        DB::table('transport_vehicles')->where('id', $f['vehicle'])
            ->update(['registration_number' => 'CHANGED-'.Str::random(10)]);
        DB::table('learners')->where('id', $f['learner']->id)
            ->update(['first_name' => 'Changed']);

        $this->assertSame($trip, $service->show($f['actor'], $trip['id']));
        $this->assertSame(
            $trip,
            $service->capture($f['actor'], ['assignment_id' => $f['assignment']['id']])
        );
        $this->assertSame(1, DB::table('audit_logs')
            ->where('record_id', $trip['id'])->where('action', 'capture')->count());
    }

    public function test_capture_blocks_late_reservations_and_reservation_cancellation(): void
    {
        $f = $this->fixture();
        app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]);
        $service = app(TransportLearnerReservationService::class);

        $this->denied(fn () => $service->create($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
            'learner_id' => $f['other']->id, 'stop_id' => $f['stop'],
        ]), 409);

        $this->denied(fn () => $service->end($f['actor'], $f['reservation']['id'], [
            'expected_version' => $f['reservation']['version'],
            'reason' => 'Late cancellation.',
        ]), 409);

        $this->assertDatabaseHas('transport_learner_reservations', [
            'id' => $f['reservation']['id'], 'active' => true,
        ]);
    }

    public function test_recording_permission_does_not_replace_assignment_responsibility(): void
    {
        $f = $this->fixture();
        $other = User::withoutGlobalScopes()->findOrFail($f['unassigned']->id);

        $this->denied(fn () => app(TransportTripService::class)->capture($other, [
            'assignment_id' => $f['assignment']['id'],
        ]), 403);

        $permission = DB::table('permissions')
            ->where('permission_name', 'record_transport_journeys')->value('id');
        DB::table('role_permissions')->where('role_id', $f['actor']->role_id)
            ->where('permission_id', $permission)->delete();

        $this->denied(fn () => app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]), 403);
    }

    public function test_future_capture_is_rejected(): void
    {
        $f = $this->fixture();
        CarbonImmutable::setTestNow('2026-11-08T09:00:00Z');

        $this->denied(fn () => app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]), 422);
    }

    public function test_snapshot_rows_cannot_be_updated_or_deleted(): void
    {
        $f = $this->fixture();
        $trip = app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]);

        foreach ([
            ['transport_trips', $trip['id']],
            ['transport_trip_roster', $trip['roster'][0]['id']],
        ] as [$table, $id]) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(function () use ($table, $id, $operation): void {
                        $query = DB::table($table)->where('id', $id);
                        if ($operation === 'update') {
                            $query->update(['id' => (string) Str::uuid()]);
                        } else {
                            $query->delete();
                        }
                    });
                    $this->fail('Captured evidence mutation was accepted.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0] ?? null);
                }
            }
        }
    }

    public function test_audit_failure_rolls_back_capture_and_unfreezes_reservations(): void
    {
        $f = $this->fixture();
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Capture audit failure.');
            });
            try {
                app(TransportTripService::class)->capture($f['actor'], [
                    'assignment_id' => $f['assignment']['id'],
                ]);
                $this->fail('Failed audit allowed capture.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Capture audit failure.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(0, DB::table('transport_trips')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame(0, DB::table('transport_trip_roster')
            ->where('school_id', $f['school']->id)->count());

        $ended = app(TransportLearnerReservationService::class)->end(
            $f['actor'], $f['reservation']['id'], [
                'expected_version' => $f['reservation']['version'],
                'reason' => 'Capture was rolled back.',
            ]
        );
        $this->assertFalse($ended['active']);
    }

    private function fixture(): array
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
            'direction' => 'to_school', 'stop_name' => 'Original stop', 'stop_order' => 1,
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
            'service_code' => 'S-'.Str::random(12), 'direction' => 'to_school',
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

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid trip operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
