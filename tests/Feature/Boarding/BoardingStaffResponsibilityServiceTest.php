<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\Hostel;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Boarding\BoardingStaffResponsibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BoardingStaffResponsibilityServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BoardingStaffResponsibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(
            BoardingStaffResponsibilityService::class
        );
    }

    public function test_same_tenant_eligible_user_can_receive_responsibility_without_teacher_record(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $this->assertFalse(
            DB::table('teachers')
                ->where('user_id', $responsible->id)
                ->exists()
        );

        $assignment = $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        $this->assertSame(
            (string) $school->id,
            (string) $assignment->school_id
        );

        $this->assertSame(
            (string) $hostel->id,
            (string) $assignment->hostel_id
        );

        $this->assertSame(
            (string) $responsible->id,
            (string) $assignment->user_id
        );

        $this->assertSame('Matron', $assignment->responsibility_role);
        $this->assertTrue($assignment->active);
        $this->assertNull($assignment->effective_to);
        $this->assertNull($assignment->ended_by);
        $this->assertNull($assignment->ended_at);
        $this->assertNull($assignment->end_reason);
        $this->assertSame(
            (string) $actor->id,
            (string) $assignment->assigned_by
        );
    }

    public function test_inactive_user_cannot_receive_responsibility(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        DB::table('users')
            ->where('id', $responsible->id)
            ->update(['active' => false]);

        $this->expectValidationField(
            'user_id',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                null,
                (string) $actor->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($school));
    }

    public function test_deleted_user_cannot_receive_responsibility(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        DB::table('users')
            ->where('id', $responsible->id)
            ->update([
                'is_deleted' => true,
            ]);

        $this->expectValidationField(
            'user_id',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                null,
                (string) $actor->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($school));
    }

    public function test_suspended_user_cannot_receive_responsibility(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        DB::table('users')
            ->where('id', $responsible->id)
            ->update([
                'suspended_at' => now(),
            ]);

        $this->expectValidationField(
            'user_id',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                null,
                (string) $actor->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($school));
    }

    public function test_foreign_tenant_user_cannot_be_assigned(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $userB = $this->user($schoolB);
        $hostelA = $this->hostel($schoolA);

        $this->expectValidationField(
            'user_id',
            fn () => $this->service->assign(
                (string) $schoolA->id,
                (string) $hostelA->id,
                (string) $userB->id,
                'Matron',
                null,
                (string) $actorA->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($schoolA));
        $this->assertSame(0, $this->assignmentCount($schoolB));
    }

    public function test_foreign_tenant_hostel_cannot_be_assigned(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $userA = $this->user($schoolA);
        $hostelB = $this->hostel($schoolB);

        $this->expectValidationField(
            'hostel_id',
            fn () => $this->service->assign(
                (string) $schoolA->id,
                (string) $hostelB->id,
                (string) $userA->id,
                'Matron',
                null,
                (string) $actorA->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($schoolA));
        $this->assertSame(0, $this->assignmentCount($schoolB));
    }

    public function test_foreign_tenant_actor_cannot_establish_assignment(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $userA = $this->user($schoolA);
        $hostelA = $this->hostel($schoolA);
        $actorB = $this->user($schoolB);

        $this->expectValidationField(
            'actor',
            fn () => $this->service->assign(
                (string) $schoolA->id,
                (string) $hostelA->id,
                (string) $userA->id,
                'Matron',
                null,
                (string) $actorB->id
            )
        );

        $this->assertSame(0, $this->assignmentCount($schoolA));
    }

    public function test_inactive_hostel_cannot_receive_assignment(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        DB::table('hostels')
            ->where('id', $hostel->id)
            ->update(['active' => false]);

        $this->expectValidationField(
            'hostel_id',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Warden',
                null,
                (string) $actor->id
            )
        );
    }

    public function test_deleted_hostel_cannot_receive_assignment(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        DB::table('hostels')
            ->where('id', $hostel->id)
            ->update([
                'is_deleted' => true,
                'active' => false,
            ]);

        $this->expectValidationField(
            'hostel_id',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Warden',
                null,
                (string) $actor->id
            )
        );
    }

    public function test_school_local_today_is_used_and_future_effective_date_is_rejected(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-06 21:30:00',
                'UTC'
            )
        );

        try {
            $school = $this->school();
            $actor = $this->user($school);
            $responsible = $this->user($school);
            $hostel = $this->hostel($school);

            $assignment = $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                null,
                (string) $actor->id
            );

            $this->assertSame(
                '2026-09-07',
                $assignment->effective_from?->toDateString()
            );

            $secondUser = $this->user($school);

            $this->expectValidationField(
                'effective_from',
                fn () => $this->service->assign(
                    (string) $school->id,
                    (string) $hostel->id,
                    (string) $secondUser->id,
                    'Warden',
                    '2026-09-08',
                    (string) $actor->id
                )
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_past_effective_date_is_allowed_and_role_is_trimmed(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $assignment = $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            '  Boarding Master  ',
            '2026-01-15',
            (string) $actor->id
        );

        $this->assertSame(
            'Boarding Master',
            $assignment->responsibility_role
        );

        $this->assertSame(
            '2026-01-15',
            $assignment->effective_from?->toDateString()
        );
    }

    public function test_duplicate_current_identity_is_translated_to_domain_validation_error(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        $this->expectValidationField(
            'responsibility_role',
            fn () => $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                null,
                (string) $actor->id
            )
        );

        $this->assertSame(
            1,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('hostel_id', $hostel->id)
                ->where('user_id', $responsible->id)
                ->where('responsibility_role', 'Matron')
                ->where('active', true)
                ->count()
        );
    }

    public function test_multiple_managers_are_allowed_for_same_hostel_role(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $first = $this->user($school);
        $second = $this->user($school);
        $hostel = $this->hostel($school);

        $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $first->id,
            'Warden',
            null,
            (string) $actor->id
        );

        $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $second->id,
            'Warden',
            null,
            (string) $actor->id
        );

        $this->assertSame(
            2,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('hostel_id', $hostel->id)
                ->where('responsibility_role', 'Warden')
                ->where('active', true)
                ->count()
        );
    }

    public function test_one_user_can_manage_multiple_hostels(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $firstHostel = $this->hostel($school, 'BOYS');
        $secondHostel = $this->hostel($school, 'GIRLS');

        foreach ([$firstHostel, $secondHostel] as $hostel) {
            $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Boarding Master',
                null,
                (string) $actor->id
            );
        }

        $this->assertSame(
            2,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('user_id', $responsible->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_same_user_and_hostel_can_hold_different_roles(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        foreach (['Matron', 'House Parent'] as $role) {
            $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                $role,
                null,
                (string) $actor->id
            );
        }

        $this->assertSame(
            2,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('hostel_id', $hostel->id)
                ->where('user_id', $responsible->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_ending_assignment_preserves_historical_episode(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-06 21:30:00',
                'UTC'
            )
        );

        try {
            $school = $this->school();
            $actor = $this->user($school);
            $responsible = $this->user($school);
            $hostel = $this->hostel($school);

            $assignment = $this->service->assign(
                (string) $school->id,
                (string) $hostel->id,
                (string) $responsible->id,
                'Matron',
                '2026-08-01',
                (string) $actor->id
            );

            $ended = $this->service->end(
                (string) $school->id,
                (string) $assignment->id,
                (string) $actor->id,
                '  Rotation completed  '
            );

            $this->assertFalse($ended->active);
            $this->assertSame(
                '2026-09-07',
                $ended->effective_to?->toDateString()
            );
            $this->assertSame(
                (string) $actor->id,
                (string) $ended->ended_by
            );
            $this->assertNotNull($ended->ended_at);
            $this->assertSame(
                'Rotation completed',
                $ended->end_reason
            );

            $this->assertDatabaseHas(
                'hostel_staff_assignments',
                [
                    'id' => $assignment->id,
                    'school_id' => $school->id,
                    'active' => false,
                    'end_reason' => 'Rotation completed',
                ]
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ended_assignment_cannot_be_ended_again(): void
    {
        [$school, $actor, $assignment] =
            $this->activeAssignmentFixture();

        $this->service->end(
            (string) $school->id,
            (string) $assignment->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'assignment',
            fn () => $this->service->end(
                (string) $school->id,
                (string) $assignment->id,
                (string) $actor->id
            )
        );
    }

    public function test_later_return_creates_new_episode_instead_of_reactivating_old_row(): void
    {
        [$school, $actor, $first] =
            $this->activeAssignmentFixture();

        $responsibleId = (string) $first->user_id;
        $hostelId = (string) $first->hostel_id;
        $role = (string) $first->responsibility_role;

        $this->service->end(
            (string) $school->id,
            (string) $first->id,
            (string) $actor->id
        );

        $second = $this->service->assign(
            (string) $school->id,
            $hostelId,
            $responsibleId,
            $role,
            null,
            (string) $actor->id
        );

        $this->assertNotSame(
            (string) $first->id,
            (string) $second->id
        );

        $this->assertSame(
            2,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('hostel_id', $hostelId)
                ->where('user_id', $responsibleId)
                ->where('responsibility_role', $role)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $school->id)
                ->where('hostel_id', $hostelId)
                ->where('user_id', $responsibleId)
                ->where('responsibility_role', $role)
                ->where('active', true)
                ->count()
        );
    }

    public function test_current_listing_excludes_ended_and_history_includes_both(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $firstUser = $this->user($school);
        $secondUser = $this->user($school);
        $hostel = $this->hostel($school);

        $endedAssignment = $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $firstUser->id,
            'Matron',
            '2026-01-01',
            (string) $actor->id
        );

        $currentAssignment = $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $secondUser->id,
            'Warden',
            '2026-02-01',
            (string) $actor->id
        );

        $this->service->end(
            (string) $school->id,
            (string) $endedAssignment->id,
            (string) $actor->id
        );

        $current = $this->service->currentForHostel(
            (string) $school->id,
            (string) $hostel->id
        );

        $history = $this->service->historyForHostel(
            (string) $school->id,
            (string) $hostel->id
        );

        $this->assertCount(1, $current);
        $this->assertSame(
            (string) $currentAssignment->id,
            (string) $current->first()->id
        );

        $this->assertCount(2, $history);

        $historyIds = $history
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $this->assertContains(
            (string) $endedAssignment->id,
            $historyIds
        );

        $this->assertContains(
            (string) $currentAssignment->id,
            $historyIds
        );
    }

    public function test_history_remains_readable_after_user_and_hostel_are_retired(): void
    {
        [$school, $actor, $assignment] =
            $this->activeAssignmentFixture();

        $this->service->end(
            (string) $school->id,
            (string) $assignment->id,
            (string) $actor->id
        );

        DB::table('users')
            ->where('id', $assignment->user_id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        DB::table('hostels')
            ->where('id', $assignment->hostel_id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        $history = $this->service->historyForHostel(
            (string) $school->id,
            (string) $assignment->hostel_id
        );

        $this->assertCount(1, $history);

        $this->assertSame(
            (string) $assignment->id,
            (string) $history->first()->id
        );
    }

    public function test_cross_tenant_assignment_and_hostel_reads_fail_closed(): void
    {
        [$schoolA, , $assignment] =
            $this->activeAssignmentFixture();

        $schoolB = $this->school();

        $this->expectModelNotFound(
            fn () => $this->service->assignment(
                (string) $schoolB->id,
                (string) $assignment->id
            )
        );

        $this->expectModelNotFound(
            fn () => $this->service->currentForHostel(
                (string) $schoolB->id,
                (string) $assignment->hostel_id
            )
        );

        $this->expectModelNotFound(
            fn () => $this->service->historyForHostel(
                (string) $schoolB->id,
                (string) $assignment->hostel_id
            )
        );

        $this->assertSame(
            1,
            DB::table('hostel_staff_assignments')
                ->where('school_id', $schoolA->id)
                ->count()
        );
    }

    public function test_generic_model_delete_is_forbidden_to_preserve_history(): void
    {
        [, , $assignment] = $this->activeAssignmentFixture();

        try {
            $assignment->delete();

            $this->fail(
                'Responsibility history was deletable through the model.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'cannot be deleted',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'hostel_staff_assignments',
            ['id' => $assignment->id]
        );
    }

    private function activeAssignmentFixture(): array
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $assignment = $this->service->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        return [$school, $actor, $assignment];
    }

    private function expectValidationField(
        string $field,
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation failure for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    private function expectModelNotFound(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'Expected tenant-scoped model lookup to fail.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    private function assignmentCount(
        School $school
    ): int {
        return DB::table('hostel_staff_assignments')
            ->where('school_id', $school->id)
            ->count();
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Responsibility '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'RS-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'RS',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Responsibility Test '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Boarding responsibility service test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'Responsibility',
            'username' => 'responsibility_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function hostel(
        School $school,
        string $type = 'GIRLS'
    ): Hostel {
        $hostel = new Hostel;

        $hostel->school_id = $school->id;
        $hostel->hostel_name = 'Responsibility Hostel '
            .Str::upper(Str::random(8));
        $hostel->hostel_type = $type;
        $hostel->capacity = 20;
        $hostel->active = true;
        $hostel->is_deleted = false;

        $hostel->save();

        return $hostel->refresh();
    }
}
