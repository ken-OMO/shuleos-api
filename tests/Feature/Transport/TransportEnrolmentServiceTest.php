<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportEnrolmentService;
use App\Services\Transport\TransportLearnerReservationService;
use Carbon\CarbonImmutable;
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
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportEnrolmentServiceTest extends TestCase
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

        CarbonImmutable::setTestNow('2026-11-06T09:00:00Z');
        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_range_reserves_every_selected_date_and_preserves_day_scholar(): void
    {
        $f = $this->fixture();
        $row = app(TransportEnrolmentService::class)->create($f['actor'], $this->input($f));

        $this->assertSame(3, $this->links($row['id'])->count());
        $this->assertSame(3, $this->activeReservations($f));
        $this->assertDatabaseHas('learners', [
            'id' => $f['learner']->id, 'mode_of_study' => 'day_scholar',
        ]);
    }

    public function test_weekday_selection_reserves_only_the_selected_dates(): void
    {
        $f = $this->fixture();
        $row = app(TransportEnrolmentService::class)->create(
            $f['actor'], $this->input($f, ['weekday_mask' => 2])
        );

        $this->assertSame(1, $this->links($row['id'])->count());
        $this->assertDatabaseHas('transport_enrolment_reservations', [
            'episode_id' => $row['id'], 'service_date' => '2026-11-10',
        ]);
    }

    public function test_missing_later_assignment_rolls_back_the_entire_range(): void
    {
        $f = $this->fixture();
        DB::table('transport_dated_assignments')
            ->where('id', $f['source_assignments']['2026-11-11'])
            ->update(['active' => false]);

        $this->denied(
            fn () => app(TransportEnrolmentService::class)
                ->create($f['actor'], $this->input($f)),
            409
        );

        $this->assertSame(0, $this->activeReservations($f));
        $this->assertSame(0, DB::table('transport_enrolment_episodes')
            ->where('school_id', $f['school']->id)->count());
    }

    public function test_transfer_preserves_source_history_and_moves_only_remaining_dates(): void
    {
        $f = $this->fixture();
        $service = app(TransportEnrolmentService::class);
        $source = $service->create($f['actor'], $this->input($f));
        $result = $service->transfer(
            $f['actor'], $source['id'], $this->transferInput($f, $source)
        );

        $this->assertSame(3, $this->links($source['id'])->count());
        $this->assertSame(2, $this->links($result['target_episode']['id'])->count());
        $this->assertSame(3, $this->activeReservations($f));
        $this->assertDatabaseHas('transport_enrolment_episodes', [
            'id' => $source['id'], 'ended_before' => '2026-11-10',
        ]);
        $this->assertSame(2, DB::table('transport_learner_reservations')
            ->where('school_id', $f['school']->id)->where('active', false)->count());

        $this->denied(
            fn () => $service->transfer(
                $f['actor'], $source['id'], $this->transferInput($f, $source)
            ),
            409
        );
    }

    public function test_full_later_target_date_restores_every_source_reservation(): void
    {
        $f = $this->fixture();
        $service = app(TransportEnrolmentService::class);
        $source = $service->create($f['actor'], $this->input($f));

        app(TransportLearnerReservationService::class)->create($f['actor'], [
            'assignment_id' => $f['target_assignments']['2026-11-11'],
            'learner_id' => $f['other']->id,
            'stop_id' => $f['stop'],
        ]);

        $this->denied(
            fn () => $service->transfer(
                $f['actor'], $source['id'], $this->transferInput($f, $source)
            ),
            409
        );

        $this->assertSame($source, $service->show($f['actor'], $source['id']));
        $this->assertSame(3, $this->activeReservations($f));
        $this->assertSame(0, DB::table('transport_enrolment_transfers')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame(1, DB::table('transport_enrolment_episodes')
            ->where('school_id', $f['school']->id)->count());
    }

    public function test_transfer_audit_failure_restores_source_episode_and_seats(): void
    {
        $f = $this->fixture();
        $service = app(TransportEnrolmentService::class);
        $source = $service->create($f['actor'], $this->input($f));
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (AuditLog $audit): void {
                if ($audit->table_name === 'transport_enrolment_transfers') {
                    throw new \RuntimeException('Transfer audit failure.');
                }
            });

            try {
                $service->transfer(
                    $f['actor'], $source['id'], $this->transferInput($f, $source)
                );
                $this->fail('Failed audit allowed transfer.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Transfer audit failure.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame($source, $service->show($f['actor'], $source['id']));
        $this->assertSame(3, $this->activeReservations($f));
        $this->assertSame(0, DB::table('transport_enrolment_transfers')
            ->where('school_id', $f['school']->id)->count());
    }

    public function test_foreign_episode_reads_and_transfers_fail_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $service = app(TransportEnrolmentService::class);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $row = $service->create($foreign['actor'], $this->input($foreign));
            $this->denied(fn () => $service->show($own['actor'], $row['id']), 404);
            $this->denied(
                fn () => $service->transfer(
                    $own['actor'], $row['id'], $this->transferInput($own, $row)
                ),
                404
            );
        }
    }

    private function fixture(): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id);
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($user->id);
        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $learner = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);
        $other = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);

        foreach ([
            'manage_transport', 'manage_transport_enrolments', 'view_transport_operations',
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
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Enrolment route',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $stop = (string) Str::uuid();
        DB::table('transport_route_stops')->insert([
            'id' => $stop, 'school_id' => $school->id, 'route_id' => $route,
            'direction' => 'to_school', 'stop_name' => 'Enrolment stop', 'stop_order' => 1,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $f = compact('school', 'actor', 'learner', 'other', 'stop');

        foreach (['source' => ['07:00', '08:00'], 'target' => ['09:00', '10:00']] as $key => $times) {
            $vehicle = (string) Str::uuid();
            DB::table('transport_vehicles')->insert([
                'id' => $vehicle, 'school_id' => $school->id,
                'registration_number' => 'BUS-'.Str::random(12),
                'ownership_type' => 'school_owned', 'learner_capacity' => 1,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            $schedule = (string) Str::uuid();
            DB::table('transport_service_schedules')->insert([
                'id' => $schedule, 'school_id' => $school->id, 'route_id' => $route,
                'service_code' => 'S-'.Str::random(12), 'direction' => 'to_school',
                'timezone' => 'Africa/Nairobi', 'weekday_mask' => 127,
                'effective_from' => '2026-11-01', 'effective_to' => '2026-12-31',
                'departure_time' => $times[0], 'completion_time' => $times[1],
                'turnaround_minutes' => 15,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $f[$key.'_schedule'] = $schedule;
            $f[$key.'_assignments'] = [];

            foreach (['2026-11-09', '2026-11-10', '2026-11-11'] as $date) {
                $assignment = app(TransportAssignmentService::class)->create($actor, [
                    'schedule_id' => $schedule, 'service_date' => $date,
                    'vehicle_id' => $vehicle, 'driver_id' => $actor->id,
                ]);
                $f[$key.'_assignments'][$date] = $assignment['id'];
            }
        }

        return $f;
    }

    private function input(array $f, array $override = []): array
    {
        return array_merge([
            'learner_id' => $f['learner']->id,
            'schedule_id' => $f['source_schedule'], 'stop_id' => $f['stop'],
            'effective_from' => '2026-11-09', 'effective_to' => '2026-11-11',
            'weekday_mask' => 127,
        ], $override);
    }

    private function transferInput(array $f, array $source): array
    {
        return [
            'expected_version' => $source['version'], 'effective_from' => '2026-11-10',
            'schedule_id' => $f['target_schedule'], 'stop_id' => $f['stop'],
            'reason' => 'Family changed service arrangements.',
        ];
    }

    private function links(string $episode)
    {
        return DB::table('transport_enrolment_reservations')->where('episode_id', $episode);
    }

    private function activeReservations(array $f): int
    {
        return DB::table('transport_learner_reservations')
            ->where('school_id', $f['school']->id)->where('learner_id', $f['learner']->id)
            ->where('active', true)->count();
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid enrolment operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
