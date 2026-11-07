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
use App\Services\Transport\TransportReportingObligationService;
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
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportReportingObligationServiceTest extends TestCase
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

    public function test_materialization_is_idempotent_and_freezes_local_deadline_evidence(): void
    {
        $f = $this->journey();
        $policy = $this->policy($f);
        $a = $this->reporting()->materialize($f['actor'], $policy['id']);
        $b = $this->reporting()->materialize($f['actor'], $policy['id']);

        $this->assertSame($a, $b);
        $this->assertSame('2026-11-09', $a['service_date']);
        $this->assertSame('Africa/Nairobi', $a['timezone']);
        $this->assertTrue(CarbonImmutable::parse($a['deadline_at'])
            ->equalTo(CarbonImmutable::parse('2026-11-09T14:00:00Z')));
        $this->assertSame(1, DB::table('transport_reporting_obligations')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('school_id', $f['school']->id)
            ->where('table_name', 'transport_reporting_obligations')->count());
    }

    public function test_deadline_and_grace_equality_are_not_late_or_overdue(): void
    {
        $f = $this->journey();
        $row = $this->reporting()->materialize($f['actor'], $this->policy($f)['id']);

        CarbonImmutable::setTestNow('2026-11-09T14:00:00Z');
        $atDeadline = $this->reporting()->show($f['actor'], $row['id']);
        $this->assertFalse($atDeadline['late_if_submitted_now']);
        $this->assertFalse($atDeadline['overdue']);

        CarbonImmutable::setTestNow('2026-11-09T14:00:01Z');
        $late = $this->reporting()->show($f['actor'], $row['id']);
        $this->assertTrue($late['late_if_submitted_now']);
        $this->assertFalse($late['overdue']);

        CarbonImmutable::setTestNow('2026-11-09T14:30:00Z');
        $this->assertFalse($this->reporting()->show($f['actor'], $row['id'])['overdue']);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $this->assertTrue($this->reporting()->show($f['actor'], $row['id'])['overdue']);
    }

    public function test_uncaptured_service_and_expected_learner_remain_visible(): void
    {
        $f = $this->fixture();
        $row = $this->reporting()->materialize($f['actor'], $this->policy($f)['id']);

        $this->assertSame(1, $row['totals']['planned_runs']);
        $this->assertSame(1, $row['totals']['uncaptured_runs']);
        $this->assertSame(1, $row['totals']['expected_journey_legs']);
        $this->assertSame(1, $row['totals']['awaiting_outcome']);
        $this->assertSame(0, $row['totals']['transported_legs']);
    }

    public function test_reporting_permission_and_concrete_assignment_are_both_required(): void
    {
        $f = $this->journey();
        $policy = $this->policy($f);
        $row = $this->reporting()->materialize($f['actor'], $policy['id']);
        $this->assertSame('unassigned', $row['responsibility_state']);

        $permission = DB::table('permissions')
            ->where('permission_name', 'submit_transport_daily_reports')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(), 'role_id' => $f['actor']->role_id,
            'permission_id' => $permission,
        ]);

        $assigned = $this->reporting()->show($f['actor'], $row['id']);
        $this->assertSame('assigned', $assigned['responsibility_state']);
        $this->assertSame([$f['actor']->id], array_column($assigned['responsible_staff'], 'id'));
    }

    public function test_totals_derive_from_journey_evidence_without_read_side_effects(): void
    {
        $f = $this->journey();
        $row = $this->reporting()->materialize($f['actor'], $this->policy($f)['id']);
        $auditCount = DB::table('audit_logs')->where('school_id', $f['school']->id)->count();

        $this->assertSame(0, $row['totals']['transported_legs']);
        $this->assertSame(1, $row['totals']['awaiting_outcome']);
        $this->reporting()->show($f['actor'], $row['id']);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('school_id', $f['school']->id)->count());

        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $picked = $this->reporting()->show($f['actor'], $row['id']);
        $this->assertSame(1, $picked['totals']['on_board']);
        $this->assertSame(1, $picked['totals']['transported_legs']);
        $this->assertSame(1, $picked['totals']['unique_learners_transported']);
        $this->assertSame(0, $picked['totals']['completed_runs']);

        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        $arrived = $this->reporting()->show($f['actor'], $row['id']);
        $this->assertSame(1, $arrived['totals']['handed_over']);
        $this->assertSame(0, $arrived['totals']['on_board']);
    }

    public function test_next_day_deadline_is_explicit_and_too_early_deadline_is_rejected(): void
    {
        $f = $this->journey();
        $this->denied(fn () => $this->policy($f, ['deadline_time' => '07:00']), 422);
        $policy = $this->policy($f, [
            'deadline_time' => '01:00', 'deadline_day_offset' => 1,
        ]);
        $row = $this->reporting()->materialize($f['actor'], $policy['id']);
        $this->assertTrue(CarbonImmutable::parse($row['deadline_at'])
            ->equalTo(CarbonImmutable::parse('2026-11-09T22:00:00Z')));
    }

    public function test_overlapping_policy_episodes_and_non_service_days_are_rejected(): void
    {
        $f = $this->journey();
        $this->policy($f);
        $this->denied(fn () => $this->policy($f), 409);

        $g = $this->journey();
        $policy = $this->policy($g, ['weekday_mask' => 2]);
        $this->denied(fn () => $this->reporting()->materialize(
            $g['actor'], $policy['id']
        ), 422);
    }

    public function test_foreign_policies_and_obligations_fail_both_ways(): void
    {
        $a = $this->journey();
        $b = $this->journey();
        $ap = $this->policy($a);
        $bp = $this->policy($b);
        $ar = $this->reporting()->materialize($a['actor'], $ap['id']);
        $br = $this->reporting()->materialize($b['actor'], $bp['id']);

        foreach ([[$a, $bp, $br], [$b, $ap, $ar]] as [$own, $policy, $row]) {
            $this->denied(fn () => $this->reporting()->materialize(
                $own['actor'], $policy['id']
            ), 404);
            $this->denied(fn () => $this->reporting()->show(
                $own['actor'], $row['id']
            ), 404);
        }
    }

    public function test_policy_and_obligation_evidence_are_immutable(): void
    {
        $f = $this->journey();
        $policy = $this->policy($f);
        $row = $this->reporting()->materialize($f['actor'], $policy['id']);

        foreach ([
            ['transport_reporting_policies', $policy['id']],
            ['transport_reporting_obligations', $row['id']],
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
                    $this->fail('Reporting evidence mutation succeeded.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0] ?? null);
                }
            }
        }
    }

    public function test_audit_failure_rolls_back_policy_and_materialization(): void
    {
        $f = $this->journey();
        $policy = $this->policy($f);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Reporting audit failure.');
            });

            foreach ([
                fn () => $this->policy($f, [
                    'effective_from' => '2026-12-01', 'effective_to' => '2026-12-10',
                ]),
                fn () => $this->reporting()->materialize($f['actor'], $policy['id']),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Audit failure allowed reporting mutation.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Reporting audit failure.', $exception->getMessage());
                }
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(1, DB::table('transport_reporting_policies')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame(0, DB::table('transport_reporting_obligations')
            ->where('school_id', $f['school']->id)->count());
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
