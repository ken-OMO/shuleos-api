<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminStreamSetupTest extends TestCase
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
                'school_name' => 'Stream Setup School',
                'short_name' => 'SSS',
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
            'grade_name' => 'Grade '.Str::upper(Str::random(5)),
            'grade_order' => random_int(1, 1000),
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('grades')->insert(
            $record
        );

        return (object) $record;
    }

    private function createStream(
        object $school,
        object $grade,
        array $attributes = []
    ): object {
        $record = array_merge([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'Stream '.Str::upper(Str::random(6)),
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('streams')->insert(
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

    public function test_school_admin_can_create_stream_without_supplying_school_id(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'East',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stream_name', 'East');

        $this->assertDatabaseHas('streams', [
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'East',
        ]);
    }

    public function test_supplied_foreign_school_id_cannot_redirect_stream_creation(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'school_id' => $foreignSchool->id,
                'grade_id' => $grade->id,
                'stream_name' => 'North',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('streams', [
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'North',
        ]);

        $this->assertDatabaseMissing('streams', [
            'school_id' => $foreignSchool->id,
            'stream_name' => 'North',
        ]);
    }

    public function test_grade_id_is_required_when_creating_stream(): void
    {
        [, , , $token] = $this->schoolAdminContext();

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'stream_name' => 'West',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'grade_id',
            ]);
    }

    public function test_stream_can_only_be_created_for_grade_in_authenticated_school(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $foreignGrade->id,
                'stream_name' => 'Foreign Grade Stream',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'grade_id',
            ]);

        $this->assertDatabaseMissing('streams', [
            'school_id' => $school->id,
            'grade_id' => $foreignGrade->id,
        ]);
    }

    public function test_stream_listing_is_tenant_scoped(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $localGrade = $this->createGrade(
            $school,
            $level
        );

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $local = $this->createStream(
            $school,
            $localGrade,
            [
                'stream_name' => 'Local Stream',
            ]
        );

        $foreign = $this->createStream(
            $foreignSchool,
            $foreignGrade,
            [
                'stream_name' => 'Foreign Stream',
            ]
        );

        $response = $this
            ->withJwtToken($token)
            ->getJson('/api/streams');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'id' => $local->id,
                'stream_name' => 'Local Stream',
            ])
            ->assertJsonMissing([
                'id' => $foreign->id,
                'stream_name' => 'Foreign Stream',
            ]);
    }

    public function test_school_admin_cannot_view_foreign_stream(): void
    {
        [, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $foreignStream = $this->createStream(
            $foreignSchool,
            $foreignGrade
        );

        $this
            ->withJwtToken($token)
            ->getJson('/api/streams/'.$foreignStream->id)
            ->assertNotFound();
    }

    public function test_school_admin_cannot_update_foreign_stream(): void
    {
        [, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $foreignStream = $this->createStream(
            $foreignSchool,
            $foreignGrade,
            [
                'stream_name' => 'Foreign Original',
            ]
        );

        $this
            ->withJwtToken($token)
            ->putJson('/api/streams/'.$foreignStream->id, [
                'stream_name' => 'Compromised',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('streams', [
            'id' => $foreignStream->id,
            'stream_name' => 'Foreign Original',
        ]);
    }

    public function test_school_admin_cannot_delete_foreign_stream(): void
    {
        [, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $foreignStream = $this->createStream(
            $foreignSchool,
            $foreignGrade
        );

        $this
            ->withJwtToken($token)
            ->deleteJson('/api/streams/'.$foreignStream->id)
            ->assertNotFound();

        $this->assertDatabaseHas('streams', [
            'id' => $foreignStream->id,
        ]);
    }

    public function test_duplicate_stream_name_within_same_grade_is_rejected(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $this->createStream(
            $school,
            $grade,
            [
                'stream_name' => 'Blue',
            ]
        );

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'Blue',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'stream_name',
            ]);
    }

    public function test_same_stream_name_is_allowed_in_another_grade(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $gradeOne = $this->createGrade(
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

        $this->createStream(
            $school,
            $gradeOne,
            [
                'stream_name' => 'Blue',
            ]
        );

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $gradeTwo->id,
                'stream_name' => 'Blue',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('streams', [
            'grade_id' => $gradeTwo->id,
            'stream_name' => 'Blue',
        ]);
    }

    public function test_same_stream_name_is_allowed_in_another_school(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $foreignSchool = $this->createSchool();

        $level = $this->createEducationLevel();

        $localGrade = $this->createGrade(
            $school,
            $level
        );

        $foreignGrade = $this->createGrade(
            $foreignSchool,
            $level
        );

        $this->createStream(
            $foreignSchool,
            $foreignGrade,
            [
                'stream_name' => 'Green',
            ]
        );

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $localGrade->id,
                'stream_name' => 'Green',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('streams', [
            'school_id' => $school->id,
            'grade_id' => $localGrade->id,
            'stream_name' => 'Green',
        ]);
    }

    public function test_unauthenticated_stream_creation_is_denied(): void
    {
        $school = $this->createSchool();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $this
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'No Auth',
            ])
            ->assertUnauthorized();
    }

    public function test_successful_stream_creation_marks_initial_setup_stream_step_ready(): void
    {
        [$school, $role, $user, $token] = $this->schoolAdminContext();

        $this->grantPermission(
            $role,
            'view_academic_setup_status'
        );

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $this
            ->withJwtToken($token)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.streams',
                false
            );

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'Ready Stream',
            ])
            ->assertCreated();

        $refreshedToken = $this->tokenFor($user);

        $this
            ->withJwtToken($refreshedToken)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.streams',
                true
            );
    }

    public function test_stream_response_does_not_expose_school_authority_object(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'Safe Stream',
            ]);

        $response
            ->assertCreated()
            ->assertJsonMissingPath('data.school');
    }

    public function test_update_cannot_rename_stream_into_duplicate_within_same_grade(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $first = $this->createStream(
            $school,
            $grade,
            [
                'stream_name' => 'Alpha',
            ]
        );

        $this->createStream(
            $school,
            $grade,
            [
                'stream_name' => 'Beta',
            ]
        );

        $this
            ->withJwtToken($token)
            ->putJson('/api/streams/'.$first->id, [
                'stream_name' => 'Beta',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'stream_name',
            ]);

        $this->assertDatabaseHas('streams', [
            'id' => $first->id,
            'stream_name' => 'Alpha',
        ]);
    }

    public function test_delete_physically_removes_stream_using_actual_schema(): void
    {
        [$school, , , $token] = $this->schoolAdminContext();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $stream = $this->createStream(
            $school,
            $grade
        );

        $this
            ->withJwtToken($token)
            ->deleteJson('/api/streams/'.$stream->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('streams', [
            'id' => $stream->id,
        ]);
    }

    public function test_database_rejects_duplicate_stream_name_within_same_grade(): void
    {
        $school = $this->createSchool();

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'Database Unique',
            'active' => true,
            'created_at' => now(),
        ]);

        $this->expectException(
            QueryException::class
        );

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'Database Unique',
            'active' => true,
            'created_at' => now(),
        ]);
    }

    public function test_user_without_manage_academics_permission_cannot_create_stream(): void
    {
        $school = $this->createSchool();

        $role = $this->createRole(
            'Restricted School User'
        );

        $user = $this->createUser(
            $school,
            $role
        );

        $level = $this->createEducationLevel();

        $grade = $this->createGrade(
            $school,
            $level
        );

        $token = $this->tokenFor($user);

        $this
            ->withJwtToken($token)
            ->postJson('/api/streams', [
                'grade_id' => $grade->id,
                'stream_name' => 'Forbidden',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('streams', [
            'grade_id' => $grade->id,
            'stream_name' => 'Forbidden',
        ]);
    }
}