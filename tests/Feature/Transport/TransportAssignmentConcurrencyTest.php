<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportLearnerReservationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportConcurrencyRunner;
use Tests\TestCase;

class TransportAssignmentConcurrencyTest extends TestCase
{
    private array $raceFixture = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->raceFixture !== []) {
                $this->removeFixture();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_vehicle_reservations_wait_then_reject_the_loser(): void
    {
        $f = $this->fixture();
        $first = $this->databasePayload($f, $f['vehicle_a'], $f['driver_a']);
        $second = $this->databasePayload($f, $f['vehicle_a'], $f['driver_b'], 2);

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertWinner($f, $first['assignment']['id']);
        $this->assertDatabaseMissing('transport_dated_assignments', [
            'id' => $second['assignment']['id'],
        ]);
    }

    public function test_cross_role_staff_reservations_wait_then_reject_the_loser(): void
    {
        $f = $this->fixture();
        $first = $this->databasePayload($f, $f['vehicle_a'], $f['driver_a']);
        $second = $this->databasePayload($f, $f['vehicle_b'], $f['driver_b'], 2);
        $second['assignment']['attendant_id'] = $f['driver_a'];

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertWinner($f, $first['assignment']['id']);
        $this->assertSame(
            0,
            DB::table('transport_staff_reservations')
                ->where('assignment_id', $second['assignment']['id'])->count()
        );
    }

    public function test_waiting_reservation_succeeds_after_first_transaction_rolls_back(): void
    {
        $f = $this->fixture();
        $first = $this->databasePayload($f, $f['vehicle_a'], $f['driver_a']);
        $first['rollback'] = true;
        $second = $this->databasePayload($f, $f['vehicle_a'], $f['driver_b'], 2);

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertWinner($f, $second['assignment']['id']);
        $this->assertDatabaseMissing('transport_dated_assignments', [
            'id' => $first['assignment']['id'],
        ]);
    }

    public function test_competing_service_requests_commit_one_assignment_and_one_audit(): void
    {
        $f = $this->fixture();
        $payload = [
            'operation' => 'service',
            'school_id' => $f['school'],
            'actor_id' => $f['actor'],
            'input' => [
                'schedule_id' => $f['schedule'],
                'service_date' => '2026-11-09',
                'vehicle_id' => $f['vehicle_a'],
                'driver_id' => $f['driver_a'],
            ],
        ];
        $second = $payload;
        $second['input']['run_number'] = 2;
        $second['input']['driver_id'] = $f['driver_b'];

        $result = (new TransportConcurrencyRunner)->run($payload, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertWinner($f, $result['first']['result']['id']);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('school_id', $f['school'])
                ->where('module', 'Transport')->where('action', 'create')
                ->where('table_name', 'transport_dated_assignments')->count()
        );
    }

    public function test_database_contenders_cannot_both_reserve_the_final_seat(): void
    {
        $f = $this->capacityFixture();
        $first = $this->reservationPayload($f, $f['learner_a']);
        $second = $this->reservationPayload($f, $f['learner_b']);

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSeatWinner($f, $first['reservation']['id']);
    }

    public function test_final_seat_becomes_available_after_contender_rolls_back(): void
    {
        $f = $this->capacityFixture();
        $first = $this->reservationPayload($f, $f['learner_a']);
        $first['rollback'] = true;
        $second = $this->reservationPayload($f, $f['learner_b']);

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSeatWinner($f, $second['reservation']['id']);
    }

    public function test_service_contenders_commit_one_final_seat_and_one_reservation_audit(): void
    {
        $f = $this->capacityFixture();
        $first = [
            'operation' => 'reservation_service',
            'school_id' => $f['school'],
            'actor_id' => $f['actor'],
            'input' => [
                'assignment_id' => $f['assignment'],
                'learner_id' => $f['learner_a'],
                'stop_id' => $f['stop'],
            ],
        ];
        $second = $first;
        $second['input']['learner_id'] = $f['learner_b'];

        $result = (new TransportConcurrencyRunner)->run($first, $second);

        $this->assertTrue($result['contention_verified']);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSeatWinner($f, $result['first']['result']['id']);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('school_id', $f['school'])
                ->where('table_name', 'transport_learner_reservations')
                ->where('action', 'create')->count()
        );
    }

    public function test_reservations_preserve_classification_and_reject_ineligible_learners(): void
    {
        $f = $this->capacityFixture();
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $service = app(TransportLearnerReservationService::class);

        DB::table('learners')->where('id', $f['learner_a'])
            ->update(['mode_of_study' => null]);
        $this->reservationDenied(fn () => $service->create($actor, [
            'assignment_id' => $f['assignment'],
            'learner_id' => $f['learner_a'], 'stop_id' => $f['stop'],
        ]), 422);

        DB::table('learners')->where('id', $f['learner_a'])
            ->update(['mode_of_study' => 'day_scholar', 'active' => false]);
        $this->reservationDenied(fn () => $service->create($actor, [
            'assignment_id' => $f['assignment'],
            'learner_id' => $f['learner_a'], 'stop_id' => $f['stop'],
        ]), 422);

        DB::table('learners')->where('id', $f['learner_a'])->update(['active' => true]);
        $row = $service->create($actor, [
            'assignment_id' => $f['assignment'],
            'learner_id' => $f['learner_a'], 'stop_id' => $f['stop'],
        ]);

        $this->assertSame('to_school', $row['direction']);
        $this->assertSame('2026-11-09', $row['service_date']);
        $this->assertDatabaseHas('learners', [
            'id' => $f['learner_a'], 'mode_of_study' => 'day_scholar',
        ]);
    }

    public function test_reserved_capacity_and_assignment_cannot_be_removed_silently(): void
    {
        $f = $this->capacityFixture(2);
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $service = app(TransportLearnerReservationService::class);

        $rows = [];
        foreach ([$f['learner_a'], $f['learner_b']] as $learner) {
            $rows[] = $service->create($actor, [
                'assignment_id' => $f['assignment'],
                'learner_id' => $learner, 'stop_id' => $f['stop'],
            ]);
        }

        try {
            DB::transaction(fn () => DB::table('transport_vehicles')
                ->where('id', $f['vehicle_a'])->update(['learner_capacity' => 1]));
            $this->fail('Reserved capacity reduction was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0] ?? null);
        }

        $assignments = app(TransportAssignmentService::class);
        $assignment = $assignments->show($actor, $f['assignment']);
        $this->reservationDenied(fn () => $assignments->end($actor, $f['assignment'], [
            'expected_version' => $assignment['version'], 'reason' => 'Blocked cancellation.',
        ]), 409);

        foreach ($rows as $row) {
            $service->end($actor, $row['id'], [
                'expected_version' => $row['version'], 'reason' => 'Journey cancelled.',
            ]);
        }

        DB::table('transport_vehicles')->where('id', $f['vehicle_a'])
            ->update(['learner_capacity' => 1]);

        $this->assertDatabaseHas('transport_vehicles', [
            'id' => $f['vehicle_a'], 'learner_capacity' => 1,
        ]);
        $this->assertSame(
            2,
            DB::table('transport_learner_reservations')
                ->where('assignment_id', $f['assignment'])->count()
        );

        $ended = $assignments->end($actor, $f['assignment'], [
            'expected_version' => $assignment['version'], 'reason' => 'Reservations released.',
        ]);
        $this->assertFalse($ended['active']);
    }

    public function test_duplicate_reservations_and_foreign_learner_references_fail(): void
    {
        $f = $this->capacityFixture(2);
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $service = app(TransportLearnerReservationService::class);
        $input = [
            'assignment_id' => $f['assignment'],
            'learner_id' => $f['learner_a'], 'stop_id' => $f['stop'],
        ];
        $service->create($actor, $input);
        $this->reservationDenied(fn () => $service->create($actor, $input), 409);

        $input['learner_id'] = (string) Str::uuid();
        $this->reservationDenied(fn () => $service->create($actor, $input), 404);
    }

    public function test_reservation_audit_failure_releases_the_seat(): void
    {
        $f = $this->capacityFixture();
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $service = app(TransportLearnerReservationService::class);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Reservation audit failure.');
            });

            try {
                $service->create($actor, [
                    'assignment_id' => $f['assignment'],
                    'learner_id' => $f['learner_a'], 'stop_id' => $f['stop'],
                ]);
                $this->fail('Failed audit allowed reservation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Reservation audit failure.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(
            0,
            DB::table('transport_learner_reservations')
                ->where('assignment_id', $f['assignment'])->count()
        );

        $row = $service->create($actor, [
            'assignment_id' => $f['assignment'],
            'learner_id' => $f['learner_b'], 'stop_id' => $f['stop'],
        ]);
        $this->assertSeatWinner($f, $row['id']);
    }

    private function capacityFixture(int $capacity = 1): array
    {
        $f = $this->fixture();

        return DB::transaction(function () use ($f, $capacity): array {
            $permission = DB::table('permissions')
                ->where('permission_name', 'manage_transport_enrolments')->value('id');
            $this->assertNotNull($permission);

            foreach (['manage_transport_enrolments', 'view_transport_operations'] as $name) {
                $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
                $this->assertNotNull($permission);
                DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'role_id' => $f['role'],
                    'permission_id' => $permission, 'created_at' => now(),
                ]);
            }

            $school = (object) ['id' => $f['school']];
            $grade = DB::table('grades')->where('school_id', $f['school'])->first();
            $stream = DB::table('streams')->where('school_id', $f['school'])
                ->where('grade_id', $grade->id)->first();
            $this->assertNotNull($grade);
            $this->assertNotNull($stream);

            $a = LearnerBuilder::create(
                $school, $grade, $stream, ['mode_of_study' => 'day_scholar']
            );
            $b = LearnerBuilder::create(
                $school, $grade, $stream, ['mode_of_study' => 'day_scholar']
            );

            DB::table('transport_vehicles')->where('id', $f['vehicle_a'])
                ->update(['learner_capacity' => $capacity]);

            $schedule = DB::table('transport_service_schedules')
                ->where('id', $f['schedule'])->first();
            $stop = (string) Str::uuid();
            DB::table('transport_route_stops')->insert([
                'id' => $stop, 'school_id' => $f['school'],
                'route_id' => $schedule->route_id, 'direction' => 'to_school',
                'stop_name' => 'Reservation stop', 'stop_order' => 1,
                'created_by' => $f['actor'], 'updated_by' => $f['actor'],
            ]);

            $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
            $assignment = app(TransportAssignmentService::class)
                ->create($actor, [
                    'schedule_id' => $f['schedule'], 'service_date' => '2026-11-09',
                    'vehicle_id' => $f['vehicle_a'], 'driver_id' => $f['driver_a'],
                ]);

            return $f + [
                'assignment' => $assignment['id'], 'stop' => $stop,
                'learner_a' => (string) $a->id, 'learner_b' => (string) $b->id,
            ];
        });
    }

    private function reservationPayload(array $f, string $learner): array
    {
        return [
            'operation' => 'reservation_database',
            'school_id' => $f['school'],
            'reservation' => [
                'id' => (string) Str::uuid(), 'school_id' => $f['school'],
                'assignment_id' => $f['assignment'],
                'learner_id' => $learner, 'stop_id' => $f['stop'],
                'created_by' => $f['actor'], 'updated_by' => $f['actor'],
            ],
        ];
    }

    private function assertSeatWinner(array $f, string $id): void
    {
        $this->assertSame(
            1,
            DB::table('transport_learner_reservations')
                ->where('assignment_id', $f['assignment'])->where('active', true)->count()
        );
        $this->assertDatabaseHas('transport_learner_reservations', [
            'id' => $id, 'assignment_id' => $f['assignment'],
            'seat_slot' => 1, 'active' => true,
        ]);
    }

    private function reservationDenied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid learner reservation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function assertWinner(array $f, string $id): void
    {
        $this->assertSame(
            1,
            DB::table('transport_dated_assignments')
                ->where('school_id', $f['school'])->count()
        );
        $this->assertDatabaseHas('transport_dated_assignments', [
            'id' => $id, 'active' => true,
        ]);
        $this->assertSame(
            1,
            DB::table('transport_staff_reservations')
                ->where('school_id', $f['school'])->where('active', true)->count()
        );
    }

    private function databasePayload(
        array $f,
        string $vehicle,
        string $driver,
        int $run = 1
    ): array {
        return [
            'operation' => 'database',
            'school_id' => $f['school'],
            'assignment' => [
                'id' => (string) Str::uuid(),
                'school_id' => $f['school'],
                'schedule_id' => $f['schedule'],
                'service_date' => '2026-11-09',
                'run_number' => $run,
                'vehicle_id' => $vehicle,
                'driver_id' => $driver,
                'created_by' => $f['actor'],
                'updated_by' => $f['actor'],
            ],
        ];
    }

    private function fixture(): array
    {
        $this->assertIsolatedDatabase();

        $f = DB::transaction(function (): array {
            $school = SchoolBuilder::create([
                'school_name' => 'Transport race '.Str::random(12),
                'short_name' => 'TRP',
                'registration_number' => 'REG-'.Str::random(12),
                'school_type' => 'Primary',
                'operating_mode' => 'day',
                'county' => 'Nairobi',
                'phone' => '+254700000001',
                'email' => 'transport-'.Str::random(12).'@example.test',
                'timezone' => 'Africa/Nairobi',
                'locale' => 'en',
                'lifecycle_state' => 'active',
            ]);
            $role = RoleBuilder::create();
            $actor = UserBuilder::create($school, $role);
            $a = UserBuilder::create($school, $role);
            $b = UserBuilder::create($school, $role);

            $year = AcademicYearBuilder::create($school);
            TermBuilder::create($school, $year);
            $grade = GradeBuilder::create($school);
            StreamBuilder::create($school, $grade);

            $permission = DB::table('permissions')
                ->where('permission_name', 'manage_transport')->value('id');
            if ($permission === null) {
                throw new \RuntimeException('Transport management permission missing.');
            }
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);

            $route = (string) Str::uuid();
            DB::table('transport_routes')->insert([
                'id' => $route, 'school_id' => $school->id,
                'route_code' => 'R-'.Str::random(12),
                'route_name' => 'Race route',
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            $vehicles = [];
            foreach ([1, 2] as $unused) {
                $id = (string) Str::uuid();
                DB::table('transport_vehicles')->insert([
                    'id' => $id, 'school_id' => $school->id,
                    'registration_number' => 'BUS-'.Str::random(12),
                    'ownership_type' => 'school_owned', 'learner_capacity' => 20,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
                $vehicles[] = $id;
            }

            $schedule = (string) Str::uuid();
            DB::table('transport_service_schedules')->insert([
                'id' => $schedule, 'school_id' => $school->id,
                'route_id' => $route, 'service_code' => 'S-'.Str::random(12),
                'direction' => 'to_school', 'timezone' => 'Africa/Nairobi',
                'weekday_mask' => 127,
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-12-31',
                'departure_time' => '07:00', 'completion_time' => '08:00',
                'turnaround_minutes' => 15,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            return [
                'school' => (string) $school->id,
                'role' => (string) $role->id,
                'actor' => (string) $actor->id,
                'driver_a' => (string) $a->id,
                'driver_b' => (string) $b->id,
                'vehicle_a' => $vehicles[0],
                'vehicle_b' => $vehicles[1],
                'schedule' => $schedule,
            ];
        });

        // Store immediately after commit so failed assertions still clean up.
        $this->raceFixture = $f;

        $this->assertTrue(
            app(SchoolSetupReadinessService::class)->isReady($f['school'])
        );
        $this->assertNotNull(User::withoutGlobalScopes()->find($f['actor']));

        return $f;
    }

    private function assertIsolatedDatabase(): void
    {
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::transactionLevel() !== 0
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated database and committed fixtures required.');
        }
    }

    private function removeFixture(): void
    {
        $this->assertIsolatedDatabase();
        $school = $this->raceFixture['school'];
        $role = $this->raceFixture['role'];

        $tables = [
            'transport_daily_reviews',
            'transport_daily_submissions',
            'transport_reporting_obligations',
            'transport_reporting_policies',
            'transport_trip_completions',
            'transport_journey_outcomes',
            'transport_exception_follow_ups',
            'transport_journey_events',
            'transport_handover_revocations',
            'transport_handover_authorizations',
            'transport_trip_roster',
            'transport_trips',
            'transport_enrolment_transfers',
            'transport_enrolment_reservations',
            'transport_enrolment_episodes',
            'transport_learner_reservations',
            'transport_staff_reservations',
            'transport_dated_assignments',
            'transport_service_schedules',
            'transport_route_stops',
            'transport_routes',
            'transport_vehicles',
            'audit_logs',
            'learners',
            'streams',
            'grades',
            'terms',
            'academic_years',
            'users',
            'schools',
            'role_permissions',
            'roles',
        ];

        DB::transaction(function () use ($tables, $school, $role): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            // Exact test-database fixture cleanup, matching Boarding's pattern.
            // Foreign-key constraint triggers remain enabled.
            foreach ($tables as $table) {
                DB::statement('ALTER TABLE "'.$table.'" DISABLE TRIGGER USER');
            }

            foreach ($tables as $table) {
                $query = DB::table($table);

                if ($table === 'schools') {
                    $query->where('id', $school)->delete();
                } elseif ($table === 'roles') {
                    $query->where('id', $role)->delete();
                } elseif ($table === 'role_permissions') {
                    $query->where('role_id', $role)->delete();
                } else {
                    $query->where('school_id', $school)->delete();
                }
            }

            foreach (array_reverse($tables) as $table) {
                DB::statement('ALTER TABLE "'.$table.'" ENABLE TRIGGER USER');
            }
        });

        $this->raceFixture = [];
    }
}
