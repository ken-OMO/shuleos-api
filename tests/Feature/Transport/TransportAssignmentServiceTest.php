<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportAssignmentServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Transport database required.');
        }

        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    public function test_vehicle_turnaround_boundary_is_enforced(): void
    {
        $f = $this->fixture();
        $service = app(TransportAssignmentService::class);
        $first = $service->create($f['actor'], $this->input($f));

        $this->assertSame(
            2,
            DB::table('transport_staff_reservations')
                ->where('assignment_id', $first['id'])->count()
        );

        $early = $this->schedule($f, '08:14', '09:00');
        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $early,
            'driver_id' => $f['other']->id,
            'attendant_id' => null,
        ])), 409);

        $boundary = $this->schedule($f, '08:15', '09:00');
        $next = $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $boundary,
        ]));
        $this->assertNotEmpty($next['id']);
    }

    public function test_staff_conflicts_apply_across_driver_and_attendant_roles(): void
    {
        $f = $this->fixture();
        $service = app(TransportAssignmentService::class);
        $service->create($f['actor'], $this->input($f));

        $secondVehicle = $this->vehicle($f);
        $secondSchedule = $this->schedule($f, '07:30', '08:30');

        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $secondSchedule,
            'vehicle_id' => $secondVehicle,
            'driver_id' => $f['attendant']->id,
            'attendant_id' => null,
        ])), 409);

        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $secondSchedule,
            'vehicle_id' => $secondVehicle,
            'driver_id' => $f['other']->id,
            'attendant_id' => $f['driver']->id,
        ])), 409);
    }

    public function test_overnight_assignment_blocks_the_following_morning(): void
    {
        $f = $this->fixture();
        $service = app(TransportAssignmentService::class);
        $night = $this->schedule($f, '23:00', '01:00', [
            'completion_day_offset' => 1,
        ]);
        $service->create($f['actor'], $this->input($f, ['schedule_id' => $night]));

        $morning = $this->schedule($f, '01:10', '02:00');
        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $morning,
            'service_date' => '2026-11-10',
        ])), 409);
    }

    public function test_future_dates_are_allowed_but_non_service_days_are_rejected(): void
    {
        $f = $this->fixture();
        $service = app(TransportAssignmentService::class);
        $future = $service->create($f['actor'], $this->input($f, [
            'service_date' => '2026-12-07',
        ]));
        $this->assertSame('2026-12-07', $future['service_date']);

        $mondays = $this->schedule($f, '10:00', '11:00', ['weekday_mask' => 1]);
        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'schedule_id' => $mondays, 'service_date' => '2026-11-10',
        ])), 422);

        $this->denied(fn () => $service->create($f['actor'], $this->input($f, [
            'service_date' => '2027-01-01',
        ])), 422);
    }

    public function test_ending_preserves_history_releases_reservations_and_rejects_stale_version(): void
    {
        $f = $this->fixture();
        $service = app(TransportAssignmentService::class);
        $row = $service->create($f['actor'], $this->input($f));
        $ended = $service->end($f['actor'], $row['id'], [
            'expected_version' => $row['version'], 'reason' => 'Service cancelled.',
        ]);

        $this->assertFalse($ended['active']);
        $this->assertSame(
            0,
            DB::table('transport_staff_reservations')
                ->where('assignment_id', $row['id'])->where('active', true)->count()
        );
        $this->assertSame(
            2,
            DB::table('transport_staff_reservations')
                ->where('assignment_id', $row['id'])->count()
        );

        $this->denied(fn () => $service->end($f['actor'], $row['id'], [
            'expected_version' => $row['version'], 'reason' => 'Stale request.',
        ]), 409);

        $replacement = $service->create($f['actor'], $this->input($f));
        $this->assertNotSame($row['id'], $replacement['id']);
    }

    public function test_foreign_resources_fail_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $service = app(TransportAssignmentService::class);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            foreach ([
                ['schedule_id' => $foreign['schedule']],
                ['vehicle_id' => $foreign['vehicle']],
                ['driver_id' => $foreign['driver']->id],
                ['attendant_id' => $foreign['attendant']->id],
            ] as $override) {
                $this->denied(
                    fn () => $service->create($own['actor'], $this->input($own, $override)),
                    404
                );
            }
        }
    }

    public function test_audit_failure_rolls_back_assignment_and_staff_reservations(): void
    {
        $f = $this->fixture();
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Assignment audit failure.');
            });

            try {
                app(TransportAssignmentService::class)->create(
                    $f['actor'], $this->input($f)
                );
                $this->fail('Failed audit allowed assignment.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Assignment audit failure.', $exception->getMessage());
            }

            $this->assertSame(
                0,
                DB::table('transport_dated_assignments')
                    ->where('school_id', $f['school']->id)->count()
            );
            $this->assertSame(
                0,
                DB::table('transport_staff_reservations')
                    ->where('school_id', $f['school']->id)->count()
            );
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
    }

    private function fixture(): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id);
        $role = RoleBuilder::create();
        $actorRow = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($actorRow->id);
        $driver = UserBuilder::create($school, RoleBuilder::create());
        $attendant = UserBuilder::create($school, RoleBuilder::create());
        $other = UserBuilder::create($school, RoleBuilder::create());

        foreach (['manage_transport', 'view_transport_operations'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id, 'permission_id' => $permission,
            ]);
        }

        $route = (string) Str::uuid();
        DB::table('transport_routes')->insert([
            'id' => $route, 'school_id' => $school->id,
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Assignment route',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $f = compact('school', 'actor', 'driver', 'attendant', 'other', 'route');
        $f['vehicle'] = $this->vehicle($f);
        $f['schedule'] = $this->schedule($f, '07:00', '08:00');

        return $f;
    }

    private function vehicle(array $f): string
    {
        $id = (string) Str::uuid();
        DB::table('transport_vehicles')->insert([
            'id' => $id, 'school_id' => $f['school']->id,
            'registration_number' => 'BUS-'.Str::random(12),
            'ownership_type' => 'school_owned', 'learner_capacity' => 20,
            'created_by' => $f['actor']->id, 'updated_by' => $f['actor']->id,
        ]);

        return $id;
    }

    private function schedule(
        array $f,
        string $departure,
        string $completion,
        array $override = []
    ): string {
        $id = (string) Str::uuid();
        DB::table('transport_service_schedules')->insert(array_merge([
            'id' => $id, 'school_id' => $f['school']->id,
            'route_id' => $f['route'], 'service_code' => 'S-'.Str::random(12),
            'direction' => 'to_school', 'timezone' => 'Africa/Nairobi',
            'weekday_mask' => 127,
            'effective_from' => '2026-11-01', 'effective_to' => '2026-12-31',
            'departure_time' => $departure, 'completion_time' => $completion,
            'turnaround_minutes' => 15,
            'created_by' => $f['actor']->id, 'updated_by' => $f['actor']->id,
        ], $override));

        return $id;
    }

    private function input(array $f, array $override = []): array
    {
        return array_merge([
            'schedule_id' => $f['schedule'], 'service_date' => '2026-11-09',
            'vehicle_id' => $f['vehicle'],
            'driver_id' => $f['driver']->id,
            'attendant_id' => $f['attendant']->id,
        ], $override);
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid assignment was accepted.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
