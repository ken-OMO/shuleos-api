<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminGradeSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    private function createSchool(
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        DB::table('schools')->insert(
            array_merge([
                'id' => $id,
                'school_name' => 'Grade Setup School',
                'short_name' => 'GSS',
                'school_code' => 'SCH-'.substr($id, 0, 8),
                'registration_number' => 'REG-'.$id,
                'school_type' => 'Primary',
                'county' => 'Nairobi',
                'phone' => '0700000000',
                'email' => $id.'@school.test',
                'timezone' => 'Africa/Nairobi',
                'locale' => 'en',
                'active' => true,
                'created_at' => now(),
            ], $attributes)
        );

        return (object) DB::table('schools')
            ->where('id', $id)
            ->first();
    }

    private function createRole(
        string $name
    ): object {
        $existing = DB::table('roles')
            ->where('role_name', $name)
            ->first();

        if ($existing) {
            return (object) $existing;
        }

        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'role_name' => $name,
            'created_at' => now(),
        ]);

        return (object) DB::table('roles')
            ->where('id', $id)
            ->first();
    }

    private function grantPermission(
        object $role,
        string $permission
    ): void {
        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                $permission
            )
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            "Permission [{$permission}] must exist."
        );

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $role->id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    private function createUser(
        object $school,
        object $role,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        DB::table('users')->insert(
            array_merge([
                'id' => $id,
                'school_id' => $school->id,
                'role_id' => $role->id,
                'username' => 'school-admin-'.substr($id, 0, 8),
                'first_name' => 'School',
                'last_name' => 'Admin',
                'email' => $id.'@user.test',
                'password_hash' => bcrypt('Password123!'),
                'active' => true,
                'first_login' => false,
                'temporary_password' => false,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes)
        );

        return (object) DB::table('users')
            ->where('id', $id)
            ->first();
    }

    private function createEducationLevel(
        array $attributes = []
    ): object {
        $record = array_merge([
            'id' => (string) Str::uuid(),
            'level_name' => 'Primary',
            'level_order' => 1,
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('education_levels')->insert(
            $record
        );

        return (object) $record;
    }

    private function createGrade(
        object $school,
        object $educationLevel,
        array $attributes = []
    ): object {
        $record = array_merge([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'education_level_id' => $educationLevel->id,
            'grade_name' => 'Grade 1',
            'grade_order' => 1,
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('grades')->insert(
            $record
        );

        return (object) $record;
    }

    private function tokenFor(
        object $user
    ): string {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }

    private function schoolAdminContext(): array
    {
        $school = $this->createSchool();

        $role = $this->createRole(
            'School Admin'
        );

        $this->grantPermission(
            $role,
            'manage_academics'
        );

        $user = $this->createUser(
            $school,
            $role
        );

        return [
            $school,
            $role,
            $user,
            $this->tokenFor($user),
        ];
    }

    private function withJwtToken(
        string $token
    ): static {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$token
        );
    }

    public function test_school_admin_can_create_grade_without_supplying_school_id(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.grade_name',
                'Grade 1'
            );

        $this->assertDatabaseHas(
            'grades',
            [
                'school_id' => $school->id,
                'education_level_id' => $level->id,
                'grade_name' => 'Grade 1',
            ]
        );
    }

    public function test_supplied_foreign_school_id_cannot_redirect_grade_creation(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'school_id' => $otherSchool->id,
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertCreated();

        $this->assertDatabaseHas(
            'grades',
            [
                'school_id' => $school->id,
                'grade_name' => 'Grade 1',
            ]
        );

        $this->assertDatabaseMissing(
            'grades',
            [
                'school_id' => $otherSchool->id,
                'grade_name' => 'Grade 1',
            ]
        );
    }

    public function test_grade_requires_valid_education_level(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => (string) Str::uuid(),
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertUnprocessable();
    }

    public function test_grade_listing_is_tenant_scoped(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $ownGrade = $this->createGrade(
            $school,
            $level
        );

        $otherSchool = $this->createSchool();

        $foreignGrade = $this->createGrade(
            $otherSchool,
            $level,
            [
                'grade_name' => 'Foreign Grade',
                'grade_order' => 2,
            ]
        );

        $response = $this
            ->withJwtToken($token)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownGrade->id,
            ])
            ->assertJsonMissing([
                'id' => $foreignGrade->id,
            ]);
    }

    public function test_school_admin_cannot_view_foreign_grade(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $otherSchool = $this->createSchool();

        $foreignGrade = $this->createGrade(
            $otherSchool,
            $level
        );

        $this
            ->withJwtToken($token)
            ->getJson('/api/grades/'.$foreignGrade->id)
            ->assertNotFound();
    }

    public function test_school_admin_cannot_update_foreign_grade(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $otherSchool = $this->createSchool();

        $foreignGrade = $this->createGrade(
            $otherSchool,
            $level
        );

        $this
            ->withJwtToken($token)
            ->putJson(
                '/api/grades/'.$foreignGrade->id,
                [
                    'grade_name' => 'Hijacked Grade',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'grades',
            [
                'id' => $foreignGrade->id,
                'grade_name' => 'Hijacked Grade',
            ]
        );
    }

    public function test_school_admin_cannot_delete_foreign_grade(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $otherSchool = $this->createSchool();

        $foreignGrade = $this->createGrade(
            $otherSchool,
            $level
        );

        $this
            ->withJwtToken($token)
            ->deleteJson('/api/grades/'.$foreignGrade->id)
            ->assertNotFound();

        $this->assertDatabaseHas(
            'grades',
            [
                'id' => $foreignGrade->id,
            ]
        );
    }

    public function test_duplicate_grade_name_is_rejected_within_same_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $this->createGrade(
            $school,
            $level
        );

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 2,
                ]
            )
            ->assertUnprocessable();
    }

    public function test_same_grade_name_is_allowed_in_another_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $otherSchool = $this->createSchool();

        $this->createGrade(
            $otherSchool,
            $level
        );

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertCreated();

        $this->assertDatabaseHas(
            'grades',
            [
                'school_id' => $school->id,
                'grade_name' => 'Grade 1',
            ]
        );
    }

    public function test_grade_order_must_be_positive(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 0,
                ]
            )
            ->assertUnprocessable();
    }

    public function test_unauthenticated_user_cannot_create_grade(): void
    {
        $level = $this->createEducationLevel();

        $this
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertUnauthorized();
    }

    public function test_successful_grade_creation_marks_grades_setup_step_ready(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $this
            ->withJwtToken($token)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.grades',
                false
            );

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertCreated();

        $this
            ->withJwtToken($token)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.grades',
                true
            );
    }

    public function test_grade_response_does_not_expose_school_authority_object(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/grades',
                [
                    'education_level_id' => $level->id,
                    'grade_name' => 'Grade 1',
                    'grade_order' => 1,
                ]
            )
            ->assertCreated()
            ->assertJsonMissingPath(
                'data.school'
            );
    }

    public function test_update_cannot_rename_into_existing_grade_in_same_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $this->createGrade(
            $school,
            $level,
            [
                'grade_name' => 'Grade 1',
                'grade_order' => 1,
            ]
        );

        $gradeTwo = $this->createGrade(
            $school,
            $level,
            [
                'grade_name' => 'Grade 2',
                'grade_order' => 2,
            ]
        );

        $this
            ->withJwtToken($token)
            ->putJson(
                '/api/grades/'.$gradeTwo->id,
                [
                    'grade_name' => 'Grade 1',
                ]
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas(
            'grades',
            [
                'id' => $gradeTwo->id,
                'grade_name' => 'Grade 2',
            ]
        );
    }

    public function test_grade_delete_removes_tenant_grade_using_existing_schema_contract(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $this
            ->withJwtToken($token)
            ->deleteJson('/api/grades/'.$grade->id)
            ->assertOk();

        $this->assertDatabaseMissing(
            'grades',
            [
                'id' => $grade->id,
            ]
        );
    }

    public function test_database_rejects_duplicate_grade_name_within_same_school(): void
    {
        $school = $this->createSchool();

        $level = $this->createEducationLevel();

        $this->createGrade(
            $school,
            $level,
            [
                'grade_name' => 'Grade 1',
                'grade_order' => 1,
            ]
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('grades')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'education_level_id' => $level->id,
            'grade_name' => 'Grade 1',
            'grade_order' => 2,
            'active' => true,
            'created_at' => now(),
        ]);
    }
}
