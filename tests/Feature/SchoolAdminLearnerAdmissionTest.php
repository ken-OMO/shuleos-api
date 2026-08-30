<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminLearnerAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_can_admit_learner_without_supplying_school_id(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertSame($school->id, $learner->school_id);
    }

    public function test_admission_sets_active_lifecycle_status_and_active_true(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/learners',
                $this->learnerPayload($grade, $stream)
            )
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertTrue((bool) $learner->active);
        $this->assertSame(
            'active',
            $learner->lifecycle_status
        );
    }

    public function test_admission_client_cannot_control_active_or_lifecycle_status(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $payload = $this->learnerPayload($grade, $stream);

        $payload['active'] = false;
        $payload['lifecycle_status'] = 'graduated';

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertTrue((bool) $learner->active);
        $this->assertSame(
            'active',
            $learner->lifecycle_status
        );
    }

    public function test_client_supplied_foreign_school_id_cannot_redirect_admission(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();
        [$grade, $stream] = $this->gradeAndStream($school);

        $payload = $this->learnerPayload($grade, $stream);
        $payload['school_id'] = $foreignSchool->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertSame($school->id, $learner->school_id);
        $this->assertNotSame($foreignSchool->id, $learner->school_id);
    }

    public function test_foreign_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$foreignGrade] = $this->gradeAndStream($foreignSchool);

        $payload = $this->learnerPayload($grade, $stream);
        $payload['grade_id'] = $foreignGrade->id;

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => 'ADM-001',
        ]);
    }

    public function test_foreign_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $foreignSchool = $this->school();

        [$grade] = $this->gradeAndStream($school);
        [, $foreignStream] = $this->gradeAndStream($foreignSchool);

        $payload = $this->learnerPayload($grade, $foreignStream);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('learners', [
            'school_id' => $school->id,
            'admission_no' => 'ADM-001',
        ]);
    }

    public function test_stream_must_belong_to_selected_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade] = $this->gradeAndStream($school, 'Grade 7', 'East');
        [, $otherStream] = $this->gradeAndStream($school, 'Grade 8', 'West');

        $payload = $this->learnerPayload($grade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $payload)
            ->assertStatus(422);
    }

    public function test_inactive_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $grade->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_inactive_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $stream->update(['active' => false]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_duplicate_active_admission_number_is_rejected_within_same_school(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->learner($school, $grade, $stream, 'ADM-001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(422);
    }

    public function test_same_admission_number_is_allowed_in_another_school(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);

        $this->learner($otherSchool, $otherGrade, $otherStream, 'ADM-001');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $this->assertSame(
            2,
            Learner::query()
                ->withoutGlobalScopes()
                ->where('admission_no', 'ADM-001')
                ->count()
        );
    }

    public function test_soft_deleted_admission_number_can_be_reused(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $old = $this->learner($school, $grade, $stream, 'ADM-001');

        DB::table('learners')
            ->where('id', $old->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();
    }

    public function test_listing_is_tenant_scoped_and_excludes_deleted_learners(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);

        $visible = $this->learner($school, $grade, $stream, 'OWN-001');
        $deleted = $this->learner($school, $grade, $stream, 'OWN-002');
        $this->learner($otherSchool, $otherGrade, $otherStream, 'FOREIGN-001');

        DB::table('learners')
            ->where('id', $deleted->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners')
            ->assertSuccessful();

        $content = $response->getContent();

        $this->assertStringContainsString($visible->id, $content);
        $this->assertStringNotContainsString($deleted->id, $content);
        $this->assertStringNotContainsString('FOREIGN-001', $content);
    }

    public function test_foreign_learner_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/learners/'.$foreign->id)
            ->assertStatus(404);
    }

    public function test_foreign_learner_cannot_be_updated(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$foreign->id, [
                'first_name' => 'Compromised',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('learners', [
            'id' => $foreign->id,
            'first_name' => 'Compromised',
        ]);
    }

    public function test_foreign_learner_cannot_be_deleted(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$otherGrade, $otherStream] = $this->gradeAndStream($otherSchool);
        $foreign = $this->learner($otherSchool, $otherGrade, $otherStream);

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/learners/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('learners', [
            'id' => $foreign->id,
            'is_deleted' => false,
        ]);
    }

    public function test_update_cannot_move_learner_to_foreign_grade_or_stream(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        $otherSchool = $this->school();

        [$grade, $stream] = $this->gradeAndStream($school);
        [$foreignGrade, $foreignStream] = $this->gradeAndStream($otherSchool);

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$learner->id, [
                'grade_id' => $foreignGrade->id,
                'stream_id' => $foreignStream->id,
            ])
            ->assertStatus(422);
    }

    public function test_update_requires_stream_to_match_effective_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] = $this->gradeAndStream($school, 'Grade 7', 'East');
        [, $otherStream] = $this->gradeAndStream($school, 'Grade 8', 'West');

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/learners/'.$learner->id, [
                'stream_id' => $otherStream->id,
            ])
            ->assertStatus(422);
    }

    public function test_delete_soft_deletes_learner_and_records_authenticated_user(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/learners/'.$learner->id)
            ->assertSuccessful();

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'school_id' => $school->id,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertNotNull(
            DB::table('learners')
                ->where('id', $learner->id)
                ->value('deleted_at')
        );
    }

    public function test_admission_response_does_not_expose_school_authority_object(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $response->assertJsonMissingPath('data.school');
    }

    public function test_admission_does_not_create_or_activate_learner_portal_account(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertSuccessful();

        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('admission_no', 'ADM-001')
            ->firstOrFail();

        $this->assertNull($learner->user_id);
        $this->assertFalse((bool) $learner->portal_enabled);
        $this->assertNull($learner->portal_activated_at);
    }

    public function test_unauthenticated_user_cannot_admit_learner(): void
    {
        $school = $this->school();
        [$grade, $stream] = $this->gradeAndStream($school);

        $this->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertUnauthorized();
    }

    public function test_user_without_manage_learners_permission_cannot_admit_learner(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        [$grade, $stream] = $this->gradeAndStream($school);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/learners', $this->learnerPayload($grade, $stream))
            ->assertStatus(403);
    }

    public function test_learner_crud_routes_use_manage_learners_permission(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach ([
            ['GET', 'api/learners'],
            ['POST', 'api/learners'],
            ['GET', 'api/learners/{id}'],
            ['PUT', 'api/learners/{id}'],
            ['DELETE', 'api/learners/{id}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route, "{$method} {$uri} route is missing.");

            $this->assertContains(
                'permission:manage_learners',
                $route->gatherMiddleware(),
                "{$method} {$uri} must require manage_learners."
            );
        }
    }

    public function test_only_learner_admission_route_requires_operational_setup_gate(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $post = $routes->first(
            fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/learners'
        );

        $this->assertNotNull($post);
        $this->assertContains('school.operational', $post->gatherMiddleware());

        foreach ([
            ['GET', 'api/learners'],
            ['GET', 'api/learners/{id}'],
            ['PUT', 'api/learners/{id}'],
            ['DELETE', 'api/learners/{id}'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array($method, $route->methods(), true)
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route);
            $this->assertNotContains('school.operational', $route->gatherMiddleware());
        }
    }

    public function test_manage_learners_permission_is_provisioned_for_authorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
            ->value('id');

        $this->assertNotNull($permissionId);

        foreach (['School Admin', 'Administrator'] as $roleName) {
            $roleId = DB::table('roles')
                ->where('role_name', $roleName)
                ->whereNull('school_id')
                ->where('system_role', true)
                ->where('active', true)
                ->value('id');

            $this->assertNotNull(
                $roleId,
                "{$roleName} system role must exist."
            );

            $this->assertTrue(
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists(),
                "{$roleName} must receive manage_learners."
            );
        }
    }

    public function test_manage_learners_permission_is_not_granted_to_unauthorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
            ->value('id');

        $this->assertNotNull($permissionId);

        $unauthorizedRoleIds = DB::table('roles')
            ->whereIn('role_name', [
                'Platform Owner',
                'Platform Super Administrator',
                'Principal',
                'Teacher',
                'Learner',
                'Parent',
            ])
            ->whereNull('school_id')
            ->where('system_role', true)
            ->pluck('id');

        $this->assertFalse(
            DB::table('role_permissions')
                ->whereIn('role_id', $unauthorizedRoleIds)
                ->where('permission_id', $permissionId)
                ->exists()
        );
    }

    private function schoolAdminWithPermission(): array
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_learners must be provisioned by the production migration.'
        );

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        return [$school, $user];
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'School '.Str::upper(Str::random(8)),
            'school_code' => 'LRN-'.Str::upper(Str::random(8)),
            'short_name' => 'LRN',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'School Admin '.Str::upper(Str::random(8)),
            'description' => 'Test school administrator',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin_'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(School $school): void
    {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.Str::upper(Str::random(6)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Readiness '.Str::upper(Str::random(8)),
            'grade_order' => random_int(1001, 2000),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Readiness '.Str::upper(Str::random(8)),
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function gradeAndStream(
        School $school,
        string $gradeName = 'Grade 7',
        string $streamName = 'East'
    ): array {
        $grade = Grade::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_name' => $gradeName,
            'grade_order' => random_int(1, 1000),
            'active' => true,
        ]);

        $stream = Stream::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => $streamName,
            'active' => true,
            'created_at' => now(),
        ]);

        return [$grade, $stream];
    }

    private function learnerPayload(Grade $grade, Stream $stream): array
    {
        return [
            'admission_no' => 'ADM-001',
            'first_name' => 'Amina',
            'middle_name' => 'Njeri',
            'last_name' => 'Kamau',
            'gender' => 'Female',
            'date_of_birth' => '2014-05-12',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'admission_date' => now()->toDateString(),
            'upi' => 'UPI-'.Str::upper(Str::random(10)),
            'assessment_no' => 'ASM-'.Str::upper(Str::random(10)),
        ];
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        string $admissionNo = 'ADM-EXISTING'
    ): Learner {
        return Learner::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'admission_no' => $admissionNo,
            'first_name' => 'Existing',
            'last_name' => 'Learner',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'admission_date' => now()->toDateString(),
            'active' => true,
            'is_deleted' => false,
            'portal_enabled' => false,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }
}
