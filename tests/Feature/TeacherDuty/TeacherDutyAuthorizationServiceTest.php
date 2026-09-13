<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyAuthorizationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

class TeacherDutyAuthorizationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private TeacherDutyAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TeacherDutyAuthorizationService::class);
    }

    public function test_reporter_with_capability_and_preserved_assignment_is_authorized(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);
        $teacher = $this->teacherForUser($actor);

        $this->assignment(
            $school,
            $periodId,
            $teacher,
            $actor
        );

        $authorized = $this->service->reporter(
            (string) $school->id,
            $periodId,
            (string) $actor->id
        );

        $this->assertSame((string) $actor->id, (string) $authorized->id);
    }

    public function test_reporter_with_capability_and_ended_preserved_assignment_is_authorized(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);
        $teacher = $this->teacherForUser($actor);

        $this->assignment(
            $school,
            $periodId,
            $teacher,
            $actor,
            false
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->where('teacher_id', $teacher->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->where('teacher_id', $teacher->id)
                ->count()
        );

        $authorized = $this->service->reporter(
            (string) $school->id,
            $periodId,
            (string) $actor->id
        );

        $this->assertSame((string) $actor->id, (string) $authorized->id);
    }

    public function test_replacement_preserves_reporting_responsibility_for_both_teachers(): void
    {
        $school = $this->school();

        $original = $this->teacherUser($school);
        $replacement = $this->teacherUser($school);

        $this->grantPermission($original, 'submit_teacher_duty_reports');
        $this->grantPermission($replacement, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $original);

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($original),
            $original,
            false
        );

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($replacement),
            $replacement
        );

        $authorizedOriginal = $this->service->reporter(
            (string) $school->id,
            $periodId,
            (string) $original->id
        );

        $authorizedReplacement = $this->service->reporter(
            (string) $school->id,
            $periodId,
            (string) $replacement->id
        );

        $this->assertSame(
            (string) $original->id,
            (string) $authorizedOriginal->id
        );

        $this->assertSame(
            (string) $replacement->id,
            (string) $authorizedReplacement->id
        );
    }

    public function test_reviewer_with_capability_does_not_require_teacher_duty_assignment(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $this->grantPermission($actor, 'review_teacher_duty_reports');

        $authorized = $this->service->reviewer(
            (string) $school->id,
            (string) $actor->id
        );

        $this->assertSame((string) $actor->id, (string) $authorized->id);

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_inactive_reporter_fails_closed(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($actor),
            $actor
        );

        $actor->forceFill([
            'active' => false,
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_deleted_reporter_fails_closed(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($actor),
            $actor
        );

        $actor->forceFill([
            'is_deleted' => true,
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_suspended_reporter_fails_closed(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($actor),
            $actor
        );

        $actor->forceFill([
            'suspended_at' => now(),
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_head_of_department_role_name_does_not_grant_reporting_authority(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        DB::table('roles')
            ->where('id', $actor->role_id)
            ->update([
                'role_name' => 'Head of Department',
            ]);

        $periodId = $this->period($school, $actor);

        $this->assignment(
            $school,
            $periodId,
            $this->teacherForUser($actor),
            $actor
        );

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_leadership_role_name_without_review_capability_does_not_grant_review_authority(): void
    {
        $school = $this->school();
        $reviewer = $this->user($school);

        $role = Role::query()
            ->whereKey($reviewer->role_id)
            ->firstOrFail();

        $role->forceFill([
            'role_name' => 'Teacher Duty Review Leader '.Str::upper(Str::random(8)),
        ])->save();

        $this->expectValidationField(
            'reviewer',
            fn () => $this->service->reviewer(
                (string) $school->id,
                (string) $reviewer->id
            )
        );
    }

    public function test_reporter_without_submit_capability_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $periodId = $this->period($school, $actor);
        $teacher = $this->teacherForUser($actor);

        $this->assignment(
            $school,
            $periodId,
            $teacher,
            $actor
        );

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_reporter_with_inactive_teacher_specialization_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);
        $teacher = $this->teacherForUser($actor);

        $this->assignment(
            $school,
            $periodId,
            $teacher,
            $actor
        );

        $teacher->forceFill([
            'active' => false,
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_reporter_with_deleted_teacher_specialization_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);
        $teacher = $this->teacherForUser($actor);

        $this->assignment(
            $school,
            $periodId,
            $teacher,
            $actor
        );

        $teacher->forceFill([
            'is_deleted' => true,
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_reporter_without_current_teacher_specialization_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_reporter_without_duty_assignment_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->teacherUser($school);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period($school, $actor);

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    public function test_reviewer_without_review_capability_is_denied(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $this->expectValidationField(
            'reviewer',
            fn () => $this->service->reviewer(
                (string) $school->id,
                (string) $actor->id
            )
        );
    }

    public function test_school_less_platform_owner_has_no_reporting_scope(): void
    {
        $school = $this->school();

        $platformRoleId = DB::table('roles')
            ->where('role_name', 'Platform Owner')
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->value('id');

        $this->assertNotNull(
            $platformRoleId,
            'Required migrated Platform Owner role was not found.'
        );

        $platformOwner = $this->user($school);

        $platformOwner->forceFill([
            'school_id' => null,
            'role_id' => (string) $platformRoleId,
        ])->save();

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $school->id,
                (string) Str::uuid(),
                (string) $platformOwner->id
            )
        );
    }

    public function test_school_less_platform_owner_has_no_review_scope(): void
    {
        $school = $this->school();

        $platformRoleId = DB::table('roles')
            ->where('role_name', 'Platform Owner')
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->value('id');

        $this->assertNotNull(
            $platformRoleId,
            'Required migrated Platform Owner role was not found.'
        );

        $platformOwner = $this->user($school);

        $platformOwner->forceFill([
            'school_id' => null,
            'role_id' => (string) $platformRoleId,
        ])->save();

        $this->expectValidationField(
            'reviewer',
            fn () => $this->service->reviewer(
                (string) $school->id,
                (string) $platformOwner->id
            )
        );
    }

    public function test_cross_school_reviewer_fails_closed(): void
    {
        $school = $this->school();
        $otherSchool = $this->school();

        $reviewer = $this->user($otherSchool);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $this->expectValidationField(
            'reviewer',
            fn () => $this->service->reviewer(
                (string) $school->id,
                (string) $reviewer->id
            )
        );
    }

    public function test_cross_school_actor_fails_closed(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actor = $this->teacherUser($schoolB);

        $this->grantPermission($actor, 'submit_teacher_duty_reports');

        $periodId = $this->period(
            $schoolA,
            $this->user($schoolA)
        );

        $this->expectValidationField(
            'actor',
            fn () => $this->service->reporter(
                (string) $schoolA->id,
                $periodId,
                (string) $actor->id
            )
        );
    }

    private function expectValidationField(
        string $field,
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation failure for [{$field}]."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty Auth '.Str::upper(Str::random(8)),
            'school_code' => 'TDA'.Str::upper(Str::random(7)),
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Teacher Duty Auth '.Str::upper(Str::random(8)),
            'description' => 'Teacher duty authorization test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty',
            'username' => 'teacher_duty_auth_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
            'is_deleted' => false,
            'suspended_at' => null,
        ]);
    }

    private function teacherUser(School $school): User
    {
        $user = $this->user($school);

        TeacherBuilder::create($school, $user);

        return $user;
    }

    private function teacherForUser(User $user): Teacher
    {
        return Teacher::query()
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function period(School $school, User $actor): string
    {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_periods')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'academic_week_id' => null,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'active' => true,
            'created_by' => $actor->id,
            'ended_by' => null,
            'ended_at' => null,
            'end_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignment(
        School $school,
        string $periodId,
        Teacher $teacher,
        User $actor,
        bool $active = true
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_assignments')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'teacher_id' => $teacher->id,
            'active' => $active,
            'assigned_by' => $actor->id,
            'ended_by' => $active ? null : $actor->id,
            'ended_at' => $active ? null : now(),
            'end_reason' => $active ? null : 'Historical assignment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)
            ->value('id');

        if (! $permissionId) {
            $permissionId = (string) Str::uuid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'permission_name' => $permissionName,
                'module_name' => 'teacher_duty',
                'description' => 'Teacher duty authorization test permission',
                'created_at' => now(),
            ]);
        }

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }
}
