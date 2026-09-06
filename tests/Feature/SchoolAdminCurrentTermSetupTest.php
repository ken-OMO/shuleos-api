<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminCurrentTermSetupTest extends TestCase
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
                'school_name' => 'Current Term Setup School',
                'short_name' => 'CTS',
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

    private function createTerm(
        object $school,
        object $academicYear,
        array $attributes = []
    ): object {
        $record = array_merge([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
            'term_name' => 'Term 1',
            'start_date' => '2026-01-05',
            'end_date' => '2026-04-10',
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('terms')->insert(
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

    private function withJwtToken(
        string $token
    ): static {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$token
        );
    }

    public function test_school_admin_can_create_term_without_supplying_school_id(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.term_name',
                'Term 1'
            );

        $this->assertDatabaseHas(
            'terms',
            [
                'school_id' => $school->id,
                'academic_year_id' => $academicYear->id,
                'term_name' => 'Term 1',
            ]
        );
    }

    public function test_supplied_foreign_school_id_cannot_redirect_term_creation(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $academicYear =
            $this->createAcademicYear($school);

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'school_id' => $otherSchool->id,
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            );

        $response->assertCreated();

        $this->assertDatabaseHas(
            'terms',
            [
                'school_id' => $school->id,
                'academic_year_id' => $academicYear->id,
                'term_name' => 'Term 1',
            ]
        );

        $this->assertDatabaseMissing(
            'terms',
            [
                'school_id' => $otherSchool->id,
                'term_name' => 'Term 1',
            ]
        );
    }

    public function test_academic_year_must_belong_to_authenticated_school(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear(
                $otherSchool,
                ['year_name' => '2027']
            );

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $otherAcademicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2027-01-05',
                    'end_date' => '2027-04-10',
                    'active' => true,
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing(
            'terms',
            [
                'academic_year_id' => $otherAcademicYear->id,
                'term_name' => 'Term 1',
            ]
        );
    }

    public function test_term_listing_is_scoped_to_authenticated_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $ownTerm = $this->createTerm(
            $school,
            $academicYear
        );

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear(
                $otherSchool,
                ['year_name' => '2027']
            );

        $foreignTerm = $this->createTerm(
            $otherSchool,
            $otherAcademicYear,
            ['term_name' => 'Foreign Term']
        );

        $response = $this
            ->withJwtToken($token)
            ->getJson('/api/terms');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownTerm->id,
            ])
            ->assertJsonMissing([
                'id' => $foreignTerm->id,
            ]);
    }

    public function test_school_admin_cannot_view_foreign_term(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear($otherSchool);

        $foreignTerm = $this->createTerm(
            $otherSchool,
            $otherAcademicYear
        );

        $this
            ->withJwtToken($token)
            ->getJson('/api/terms/'.$foreignTerm->id)
            ->assertNotFound();
    }

    public function test_school_admin_cannot_update_foreign_term(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear($otherSchool);

        $foreignTerm = $this->createTerm(
            $otherSchool,
            $otherAcademicYear
        );

        $this
            ->withJwtToken($token)
            ->putJson(
                '/api/terms/'.$foreignTerm->id,
                [
                    'term_name' => 'Hijacked Term',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'terms',
            [
                'id' => $foreignTerm->id,
                'term_name' => 'Hijacked Term',
            ]
        );
    }

    public function test_school_admin_cannot_delete_foreign_term(): void
    {
        [, , , $token] =
            $this->schoolAdminContext();

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear($otherSchool);

        $foreignTerm = $this->createTerm(
            $otherSchool,
            $otherAcademicYear
        );

        $this
            ->withJwtToken($token)
            ->deleteJson('/api/terms/'.$foreignTerm->id)
            ->assertNotFound();

        $this->assertDatabaseHas(
            'terms',
            [
                'id' => $foreignTerm->id,
            ]
        );
    }

    public function test_term_end_date_must_be_after_start_date(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-04-10',
                    'end_date' => '2026-01-05',
                    'active' => true,
                ]
            );

        $response->assertUnprocessable();
    }

    public function test_duplicate_term_name_is_rejected_within_same_academic_year(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $this->createTerm(
            $school,
            $academicYear
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-05-01',
                    'end_date' => '2026-08-01',
                    'active' => true,
                ]
            );

        $response->assertUnprocessable();

        $this->assertSame(
            1,
            DB::table('terms')
                ->where('school_id', $school->id)
                ->where(
                    'academic_year_id',
                    $academicYear->id
                )
                ->where('term_name', 'Term 1')
                ->count()
        );
    }

    public function test_same_term_name_is_allowed_in_another_academic_year(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $year2026 =
            $this->createAcademicYear($school);

        $year2027 =
            $this->createAcademicYear(
                $school,
                [
                    'year_name' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                ]
            );

        $this->createTerm(
            $school,
            $year2026
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $year2027->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2027-01-05',
                    'end_date' => '2027-04-10',
                    'active' => true,
                ]
            );

        $response->assertCreated();

        $this->assertDatabaseHas(
            'terms',
            [
                'school_id' => $school->id,
                'academic_year_id' => $year2027->id,
                'term_name' => 'Term 1',
            ]
        );
    }

    public function test_same_term_name_is_allowed_in_another_school(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $otherSchool = $this->createSchool();

        $otherAcademicYear =
            $this->createAcademicYear(
                $otherSchool,
                ['year_name' => '2027']
            );

        $this->createTerm(
            $otherSchool,
            $otherAcademicYear
        );

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            );

        $response->assertCreated();
    }

    public function test_unauthenticated_user_cannot_create_term(): void
    {
        $school = $this->createSchool();

        $academicYear =
            $this->createAcademicYear($school);

        $this
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            )
            ->assertUnauthorized();
    }

    public function test_active_term_creation_marks_current_term_setup_step_ready(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $this
            ->withJwtToken($token)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.current_term',
                false
            );

        $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            )
            ->assertCreated();

        $this
            ->withJwtToken($token)
            ->getJson('/api/admin/school/setup')
            ->assertOk()
            ->assertJsonPath(
                'data.steps.current_term',
                true
            );
    }

    public function test_term_response_does_not_expose_loaded_school_authority_object(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $response = $this
            ->withJwtToken($token)
            ->postJson(
                '/api/terms',
                [
                    'academic_year_id' => $academicYear->id,
                    'term_name' => 'Term 1',
                    'start_date' => '2026-01-05',
                    'end_date' => '2026-04-10',
                    'active' => true,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonMissingPath('data.school');
    }

    public function test_update_cannot_produce_invalid_effective_date_range(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $term = $this->createTerm(
            $school,
            $academicYear
        );

        $response = $this
            ->withJwtToken($token)
            ->putJson(
                '/api/terms/'.$term->id,
                [
                    'start_date' => '2026-05-01',
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas(
            'terms',
            [
                'id' => $term->id,
                'start_date' => '2026-01-05',
                'end_date' => '2026-04-10',
            ]
        );
    }

    public function test_update_cannot_rename_into_existing_term_in_same_academic_year(): void
    {
        [$school, , , $token] =
            $this->schoolAdminContext();

        $academicYear =
            $this->createAcademicYear($school);

        $this->createTerm(
            $school,
            $academicYear,
            ['term_name' => 'Term 1']
        );

        $termTwo = $this->createTerm(
            $school,
            $academicYear,
            [
                'term_name' => 'Term 2',
                'start_date' => '2026-05-01',
                'end_date' => '2026-08-01',
            ]
        );

        $response = $this
            ->withJwtToken($token)
            ->putJson(
                '/api/terms/'.$termTwo->id,
                [
                    'term_name' => 'Term 1',
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas(
            'terms',
            [
                'id' => $termTwo->id,
                'term_name' => 'Term 2',
            ]
        );
    }

    public function test_database_rejects_duplicate_term_name_within_same_academic_year(): void
    {
        $school = $this->createSchool();

        $academicYear =
            $this->createAcademicYear($school);

        $this->createTerm(
            $school,
            $academicYear,
            [
                'term_name' => 'Term 1',
            ]
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
            'term_name' => 'Term 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-08-01',
            'active' => true,
            'created_at' => now(),
        ]);
    }
}
