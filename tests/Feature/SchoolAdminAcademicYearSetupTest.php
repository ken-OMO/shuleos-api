<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminAcademicYearSetupTest extends TestCase
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
                'school_name' => 'Academic Setup School',
                'short_name' => 'ASS',
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

    private function tokenFor(
        object $user
    ): string {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }

    private function createAcademicYear(
        object $school,
        array $attributes = []
    ): object {
        $record = array_merge([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'year_name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('academic_years')->insert(
            $record
        );

        return (object) $record;
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

    public function test_school_admin_can_create_academic_year_without_supplying_school_id(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'active' => true,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.year_name',
                '2027'
            );

        $this->assertDatabaseHas(
            'academic_years',
            [
                'school_id' => $school->id,
                'year_name' => '2027',
            ]
        );
    }

    public function test_supplied_foreign_school_id_cannot_redirect_academic_year_creation(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool([
            'school_name' => 'Other School',
        ]);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'school_id' => $otherSchool->id,
                    'year_name' => '2028',
                    'start_date' => '2028-01-01',
                    'end_date' => '2028-12-31',
                    'active' => true,
                ]
            )
            ->assertCreated();

        $this->assertDatabaseHas(
            'academic_years',
            [
                'school_id' => $school->id,
                'year_name' => '2028',
            ]
        );

        $this->assertDatabaseMissing(
            'academic_years',
            [
                'school_id' => $otherSchool->id,
                'year_name' => '2028',
            ]
        );
    }

    public function test_academic_year_listing_is_tenant_scoped(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $own = $this->createAcademicYear(
            $school,
            [
                'year_name' => '2026',
            ]
        );

        $foreign = $this->createAcademicYear(
            $otherSchool,
            [
                'year_name' => '2099',
            ]
        );

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/academic-years'
            )
            ->assertOk();

        $payload = $response->getContent();

        $this->assertStringContainsString(
            $own->id,
            $payload
        );

        $this->assertStringNotContainsString(
            $foreign->id,
            $payload
        );
    }

    public function test_school_admin_cannot_view_foreign_academic_year(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $foreign = $this->createAcademicYear(
            $otherSchool
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/academic-years/'.$foreign->id
            )
            ->assertNotFound();
    }

    public function test_school_admin_cannot_update_foreign_academic_year(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $foreign = $this->createAcademicYear(
            $otherSchool
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/academic-years/'.$foreign->id,
                [
                    'year_name' => 'Compromised',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'academic_years',
            [
                'id' => $foreign->id,
                'year_name' => '2026',
            ]
        );
    }

    public function test_school_admin_cannot_delete_foreign_academic_year(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $foreign = $this->createAcademicYear(
            $otherSchool
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->deleteJson(
                '/api/academic-years/'.$foreign->id
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'academic_years',
            [
                'id' => $foreign->id,
            ]
        );
    }

    public function test_academic_year_requires_valid_date_range(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-12-31',
                    'end_date' => '2027-01-01',
                    'active' => true,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'end_date',
            ]);
    }

    public function test_duplicate_academic_year_name_is_rejected_within_same_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $this->createAcademicYear(
            $school,
            [
                'year_name' => '2026',
            ]
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2026',
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'active' => true,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'year_name',
            ]);
    }

    public function test_same_academic_year_name_can_exist_in_different_schools(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $this->createAcademicYear(
            $otherSchool,
            [
                'year_name' => '2027',
            ]
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'active' => true,
                ]
            )
            ->assertCreated();

        $this->assertDatabaseHas(
            'academic_years',
            [
                'school_id' => $school->id,
                'year_name' => '2027',
            ]
        );
    }

    public function test_unauthenticated_user_cannot_create_academic_year(): void
    {
        $this
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'active' => true,
                ]
            )
            ->assertUnauthorized();
    }

    public function test_successful_academic_year_setup_marks_initial_setup_step_complete(): void
    {
        [, $role, $user, $token] =
            $this->schoolAdminContext();

        $this->grantPermission(
            $role,
            'view_academic_setup_status'
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'active' => true,
                ]
            )
            ->assertCreated();

        $token = $this->tokenFor($user);

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
                'data.steps.academic_year',
                true
            );
    }

    public function test_academic_year_response_does_not_expose_school_authority_object(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->postJson(
                '/api/academic-years',
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'active' => true,
                ]
            )
            ->assertCreated();

        $payload = $response->json(
            'data'
        );

        $this->assertIsArray(
            $payload
        );

        $this->assertArrayNotHasKey(
            'school',
            $payload
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $payload
        );
    }

    public function test_academic_year_update_rejects_invalid_date_range(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $year = $this->createAcademicYear(
            $school,
            [
                'year_name' => '2027',
                'start_date' => '2027-01-01',
                'end_date' => '2027-12-31',
            ]
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/academic-years/'.$year->id,
                [
                    'start_date' => '2027-12-31',
                    'end_date' => '2027-01-01',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'end_date',
            ]);

        $this->assertDatabaseHas(
            'academic_years',
            [
                'id' => $year->id,
                'start_date' => '2027-01-01',
                'end_date' => '2027-12-31',
            ]
        );
    }

    public function test_academic_year_update_rejects_duplicate_name_within_same_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $existing = $this->createAcademicYear(
            $school,
            [
                'year_name' => '2027',
            ]
        );

        $year = $this->createAcademicYear(
            $school,
            [
                'year_name' => '2028',
                'start_date' => '2028-01-01',
                'end_date' => '2028-12-31',
            ]
        );

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/academic-years/'.$year->id,
                [
                    'year_name' => $existing->year_name,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'year_name',
            ]);

        $this->assertDatabaseHas(
            'academic_years',
            [
                'id' => $year->id,
                'year_name' => '2028',
            ]
        );
    }

    public function test_database_rejects_duplicate_academic_year_name_within_same_school(): void
    {
        $school = $this->createSchool();

        $this->createAcademicYear(
            $school,
            [
                'year_name' => '2027',
            ]
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('academic_years')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'year_name' => '2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'active' => true,
            'created_at' => now(),
        ]);
    }
}
