<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportLearnerReservationService;
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

class TransportHandoverAuthorizationServiceTest extends TestCase
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

    public function test_guardian_link_requires_collection_eligibility_and_explicit_authorization(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $input = $this->recipient($f);

        DB::table('learner_parents')->where('id', $input['guardian_link_id'])
            ->update(['can_pick_learner' => false]);
        $this->denied(fn () => $this->service()->create($f['actor'], $input), 422);

        DB::table('learner_parents')->where('id', $input['guardian_link_id'])
            ->update(['can_pick_learner' => true]);
        $authorization = $this->service()->create($f['actor'], $input);

        $this->assertNull($authorization['revocation']);
        $this->assertSame($input['guardian_id'], $authorization['identity']['guardian_id']);
        $this->assertSame($f['assignment']['id'], $authorization['scope']['assignment_id']);
        $this->assertSame('to_school', $authorization['scope']['direction']);
        $this->assertArrayNotHasKey('phone', $authorization['identity']);

        DB::table('parents')->where('id', $input['guardian_id'])
            ->update(['first_name' => 'Changed', 'active' => false]);
        DB::table('learner_parents')->where('id', $input['guardian_link_id'])
            ->update(['can_pick_learner' => false]);

        $this->assertSame(
            $authorization,
            $this->service()->show($f['actor'], $authorization['id'])
        );
    }

    public function test_school_counterpart_is_direction_scoped_and_distinct_from_crew(): void
    {
        $f = $this->fixture();
        $input = [
            'assignment_id' => $f['assignment']['id'],
            'kind' => 'school_receiver',
            'staff_user_id' => $f['unassigned']->id,
            'valid_from' => '2026-11-09',
            'valid_to' => '2026-11-09',
            'reason' => 'Assigned school arrival receiver.',
        ];

        $authorization = $this->service()->create($f['actor'], $input);
        $this->assertSame($f['unassigned']->id, $authorization['identity']['user_id']);

        $this->denied(fn () => $this->service()->create($f['actor'], [
            ...$input, 'staff_user_id' => $f['actor']->id,
        ]), 422);
        $this->denied(fn () => $this->service()->create($f['actor'], [
            ...$input, 'kind' => 'school_releaser',
        ]), 422);
        $this->denied(fn () => $this->service()->create($f['actor'], [
            ...$input, 'valid_to' => '2026-11-08',
        ]), 422);
    }

    public function test_revocation_preserves_evidence_changes_version_and_rejects_stale_token(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $issued = $this->service()->create($f['actor'], $this->recipient($f));

        $revoked = $this->service()->revoke($f['actor'], $issued['id'], [
            'expected_version' => $issued['version'],
            'reason' => 'Collection arrangement withdrawn.',
        ]);

        $this->assertNotSame($issued['version'], $revoked['version']);
        $this->assertSame($issued['identity'], $revoked['identity']);
        $this->assertSame($issued['scope'], $revoked['scope']);
        $this->assertSame($issued['authorized_at'], $revoked['authorized_at']);
        $this->assertNotNull($revoked['revocation']);

        $this->denied(fn () => $this->service()->revoke($f['actor'], $issued['id'], [
            'expected_version' => $issued['version'], 'reason' => 'Stale retry.',
        ]), 409);

        $this->assertSame($revoked, $this->service()->revoke($f['actor'], $issued['id'], [
            'expected_version' => $revoked['version'], 'reason' => 'Already revoked.',
        ]));
        $this->assertSame(1, DB::table('transport_handover_revocations')
            ->where('authorization_id', $issued['id'])->count());
    }

    public function test_foreign_reads_revocations_and_recipient_references_fail_both_ways(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $this->grant($a);
        $this->grant($b);
        $ai = $this->recipient($a);
        $bi = $this->recipient($b);
        $ar = $this->service()->create($a['actor'], $ai);
        $br = $this->service()->create($b['actor'], $bi);

        foreach ([[$a, $br, $bi], [$b, $ar, $ai]] as [$own, $foreign, $foreignInput]) {
            $this->denied(fn () => $this->service()->show(
                $own['actor'], $foreign['id']
            ), 404);
            $this->denied(fn () => $this->service()->revoke($own['actor'], $foreign['id'], [
                'expected_version' => $foreign['version'], 'reason' => 'Foreign attempt.',
            ]), 404);
            $this->denied(fn () => $this->service()->create($own['actor'], [
                ...$foreignInput, 'assignment_id' => $own['assignment']['id'],
            ]), 404);
        }
    }

    public function test_management_permission_does_not_grant_guardian_authorization(): void
    {
        $f = $this->fixture();
        $input = $this->recipient($f);
        $this->denied(fn () => $this->service()->create($f['actor'], $input), 403);
        $this->assertSame(0, DB::table('transport_handover_authorizations')
            ->where('school_id', $f['school']->id)->count());
    }

    public function test_authorization_and_revocation_rows_are_immutable(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $issued = $this->service()->create($f['actor'], $this->recipient($f));
        $revoked = $this->service()->revoke($f['actor'], $issued['id'], [
            'expected_version' => $issued['version'], 'reason' => 'Withdrawn.',
        ]);

        foreach ([
            ['transport_handover_authorizations', $issued['id']],
            ['transport_handover_revocations', $revoked['revocation']['id']],
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
                    $this->fail('Immutable evidence mutation succeeded.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0] ?? null);
                }
            }
        }
    }

    public function test_audit_failure_rolls_back_both_issue_and_revocation(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $input = $this->recipient($f);
        $issued = $this->service()->create($f['actor'], $input);
        $dispatcher = AuditLog::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Handover audit failure.');
            });

            foreach ([
                fn () => $this->service()->create($f['actor'], $input),
                fn () => $this->service()->revoke($f['actor'], $issued['id'], [
                    'expected_version' => $issued['version'], 'reason' => 'Withdrawn.',
                ]),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Audit failure allowed a mutation.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Handover audit failure.', $exception->getMessage());
                }
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertSame(1, DB::table('transport_handover_authorizations')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame(0, DB::table('transport_handover_revocations')
            ->where('school_id', $f['school']->id)->count());
        $this->assertSame($issued, $this->service()->show($f['actor'], $issued['id']));
    }

    public function test_ineligible_school_counterparts_are_rejected(): void
    {
        $f = $this->fixture();
        $input = [
            'assignment_id' => $f['assignment']['id'],
            'kind' => 'school_receiver',
            'staff_user_id' => $f['unassigned']->id,
            'valid_from' => '2026-11-09',
            'valid_to' => '2026-11-09',
            'reason' => 'School receiving responsibility.',
        ];

        foreach ([
            ['active' => false],
            ['is_deleted' => true],
            ['suspended_at' => DB::raw('clock_timestamp()')],
            ['account_locked_until' => DB::raw("clock_timestamp() + interval '1 hour'")],
        ] as $state) {
            DB::table('users')->where('id', $f['unassigned']->id)->update([
                'active' => true,
                'is_deleted' => false,
                'suspended_at' => null,
                'account_locked_until' => null,
                ...$state,
            ]);

            $this->denied(fn () => $this->service()->create($f['actor'], $input), 422);
        }

        $this->assertSame(0, DB::table('transport_handover_authorizations')
            ->where('school_id', $f['school']->id)->count());
    }

    private function service(): TransportHandoverAuthorizationService
    {
        return app(TransportHandoverAuthorizationService::class);
    }

    private function grant(array $f): void
    {
        $permission = DB::table('permissions')
            ->where('permission_name', 'authorize_transport_handover')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(), 'role_id' => $f['actor']->role_id,
            'permission_id' => $permission,
        ]);
    }

    private function recipient(array $f): array
    {
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
            'active' => true, 'is_deleted' => false, 'can_pick_learner' => true,
            'portal_enabled' => false,
        ]);

        return [
            'assignment_id' => $f['assignment']['id'],
            'kind' => 'guardian_recipient',
            'learner_id' => $f['learner']->id,
            'stop_id' => $f['stop'],
            'guardian_id' => $guardian,
            'guardian_link_id' => $link,
            'valid_from' => '2026-11-09',
            'valid_to' => '2026-11-09',
            'reason' => 'Approved collection arrangement for this dated run.',
        ];
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
        } catch (ValidationException $exception) {
            $this->assertSame(422, $status);
            $this->assertNotEmpty($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
