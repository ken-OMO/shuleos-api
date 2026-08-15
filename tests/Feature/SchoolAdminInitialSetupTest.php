<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminInitialSetupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_activated_school_admin_can_view_own_initial_setup_status(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.school_id',
                $school->id
            )
            ->assertJsonStructure([
                'data' => [
                    'school_id',
                    'setup_complete',
                    'steps' => [
                        'school_profile',
                        'academic_year',
                        'current_term',
                        'grades',
                        'streams',
                    ],
                ],
            ]);
    }

    public function test_new_school_initial_setup_is_incomplete_until_required_steps_exist(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertOk();

        $response
            ->assertJsonPath(
                'data.setup_complete',
                false
            )
            ->assertJsonPath(
                'data.steps.school_profile',
                false
            )
            ->assertJsonPath(
                'data.steps.academic_year',
                false
            )
            ->assertJsonPath(
                'data.steps.current_term',
                false
            )
            ->assertJsonPath(
                'data.steps.grades',
                false
            )
            ->assertJsonPath(
                'data.steps.streams',
                false
            );
    }

    public function test_setup_complete_becomes_true_only_when_all_required_steps_are_ready(): void
    {
        [$school, $role] = $this->schoolFixture();

        $school->forceFill([
            'short_name' => 'Setup',
            'registration_number' => 'REG-'.Str::upper(Str::random(8)),
            'school_type' => 'Junior School',
            'county' => 'Nairobi',
            'phone' => '07'.random_int(10000000, 99999999),
            'email' => 'setup-'.Str::lower(Str::random(8)).'@example.test',
        ])->save();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $academicYearId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => '2026',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Term 1',
            'active' => true,
            'created_at' => now(),
        ]);

        $gradeId = (string) Str::uuid();

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Grade 7',
            'grade_order' => 7,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'North',
            'active' => true,
            'created_at' => now(),
        ]);

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.steps.school_profile',
                true
            )
            ->assertJsonPath(
                'data.steps.academic_year',
                true
            )
            ->assertJsonPath(
                'data.steps.current_term',
                true
            )
            ->assertJsonPath(
                'data.steps.grades',
                true
            )
            ->assertJsonPath(
                'data.steps.streams',
                true
            )
            ->assertJsonPath(
                'data.setup_complete',
                true
            );
    }

    public function test_other_school_records_do_not_satisfy_initial_setup_steps(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        [$otherSchool] = $this->schoolFixture();

        $otherAcademicYearId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $otherAcademicYearId,
            'school_id' => $otherSchool->id,
            'year_name' => '2026',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $otherSchool->id,
            'academic_year_id' => $otherAcademicYearId,
            'term_name' => 'Term 1',
            'active' => true,
            'created_at' => now(),
        ]);

        $otherGradeId = (string) Str::uuid();

        DB::table('grades')->insert([
            'id' => $otherGradeId,
            'school_id' => $otherSchool->id,
            'grade_name' => 'Grade 7',
            'grade_order' => 7,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $otherSchool->id,
            'grade_id' => $otherGradeId,
            'stream_name' => 'East',
            'active' => true,
            'created_at' => now(),
        ]);

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertOk();

        $response
            ->assertJsonPath(
                'data.steps.academic_year',
                false
            )
            ->assertJsonPath(
                'data.steps.current_term',
                false
            )
            ->assertJsonPath(
                'data.steps.grades',
                false
            )
            ->assertJsonPath(
                'data.steps.streams',
                false
            )
            ->assertJsonPath(
                'data.setup_complete',
                false
            );
    }

    public function test_unauthenticated_user_cannot_view_school_initial_setup_status(): void
    {
        $this
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertUnauthorized();
    }

    public function test_platform_owner_cannot_use_school_initial_setup_endpoint(): void
    {
        $role = Role::query()
            ->where(
                'role_name',
                'Platform Owner'
            )
            ->whereNull(
                'school_id'
            )
            ->where(
                'system_role',
                true
            )
            ->where(
                'active',
                true
            )
            ->firstOrFail();

        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $role->id,
            'username' => 'platform.owner.'.Str::lower(Str::random(8)),
            'password_hash' => Hash::make(
                'PlatformOwnerPermanent123!'
            ),
            'email' => 'platform-owner-'.Str::lower(Str::random(8)).'@example.test',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
            'temporary_password' => false,
            'temporary_password_expires_at' => null,
            'force_password_reset_at' => null,
            'activated_at' => now(),
            'email_verified_at' => now(),
            'auth_generation' => 1,
        ]);

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertForbidden();
    }

    public function test_school_admin_cannot_choose_another_school_for_setup_status(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        [$otherSchool] = $this->schoolFixture();

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup?school_id='.$otherSchool->id
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.school_id',
                $school->id
            );

        $this->assertNotSame(
            $otherSchool->id,
            $response->json(
                'data.school_id'
            )
        );
    }

    public function test_school_initial_setup_status_response_does_not_expose_internal_authority_fields(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertOk();

        $payload = $response->getContent();

        foreach ([
            'role_id',
            'actor_id',
            'password_hash',
            'auth_generation',
            'lifecycle_version',
            'temporary_password',
        ] as $forbiddenField) {
            $this->assertStringNotContainsString(
                $forbiddenField,
                $payload
            );
        }
    }

    private function schoolFixture(): array
    {
        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Initial Setup '.Str::upper(Str::random(4)),
            'school_code' => 'IS'.Str::upper(Str::random(8)),
            'login_prefix' => 'IS',
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'lifecycle_version' => 1,
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
        ]);

        $role = Role::query()
            ->where(
                'role_name',
                'School Admin'
            )
            ->whereNull(
                'school_id'
            )
            ->where(
                'system_role',
                true
            )
            ->where(
                'active',
                true
            )
            ->firstOrFail();

        return [
            $school,
            $role,
        ];
    }

    private function schoolAdmin(
        School $school,
        Role $role
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'username' => 'school.admin.'.Str::lower(Str::random(8)),
            'password_hash' => Hash::make(
                'SchoolAdminPermanent123!'
            ),
            'email' => 'school-admin-'.Str::lower(Str::random(8)).'@example.test',
            'first_name' => 'School',
            'last_name' => 'Administrator',
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
            'temporary_password' => false,
            'temporary_password_expires_at' => null,
            'force_password_reset_at' => null,
            'activated_at' => now(),
            'email_verified_at' => now(),
            'auth_generation' => 1,
        ]);
    }
}
