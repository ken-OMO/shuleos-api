<?php

namespace Tests\Feature;

use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Grade;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $schoolId;

    private string $otherSchoolId;

    private string $roleId;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->schoolId = (string) Str::uuid();
        $this->otherSchoolId = (string) Str::uuid();
        DB::table('schools')->insert([
            [
                'id' => $this->schoolId,
                'school_name' => 'Platform Security School',
                'school_code' => 'PSS-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
            [
                'id' => $this->otherSchoolId,
                'school_name' => 'Platform Security Other School',
                'school_code' => 'PSO-'.strtoupper(Str::random(8)),
                'active' => true,
                'is_deleted' => false,
            ],
        ]);

        $this->roleId = DB::table('roles')
            ->where('role_name', 'Teacher')
            ->value('id');

        if (! $this->roleId) {
            throw new \RuntimeException('Required migrated Teacher role was not found.');
        }

        $this->userId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $this->userId,
            'school_id' => $this->schoolId,
            'role_id' => $this->roleId,
            'username' => 'platform-security-'.Str::lower(Str::random(8)),
            'password_hash' => 'not-used',
            'first_name' => 'Platform',
            'last_name' => 'Security',
            'active' => true,
            'first_login' => false,
            'auth_generation' => 1,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::setUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($this->userId)
        );
    }

    protected function tearDown(): void
    {
        Auth::forgetUser();
        parent::tearDown();
    }

    public function test_tenant_models_hide_other_school_records(): void
    {
        DB::table('grades')->insert([
            ['id' => (string) Str::uuid(), 'school_id' => $this->schoolId, 'grade_name' => 'Own grade', 'grade_order' => 1],
            ['id' => (string) Str::uuid(), 'school_id' => $this->otherSchoolId, 'grade_name' => 'Foreign grade', 'grade_order' => 2],
        ]);

        $this->assertSame(['Own grade'], Grade::pluck('grade_name')->all());
    }

    public function test_module_permission_allows_a_primary_role_granted_capability(): void
    {
        $permissionId = $this->permission('create_exam');

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $this->roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        $response = app(ModulePermissionMiddleware::class)->handle(
            Request::create('/api/exams', 'GET'),
            fn () => response('allowed')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_module_permission_allows_a_secondary_role_granted_capability(): void
    {
        $secondaryRoleId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $secondaryRoleId,
            'role_name' => 'Platform Security Exam Reviewer',
            'school_id' => $this->schoolId,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $this->userId,
            'role_id' => $secondaryRoleId,
        ]);

        $permissionId = $this->permission('create_exam');

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $secondaryRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        $user = User::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->userId);

        $authContext = app(AuthContextService::class);

        $this->assertFalse(
            DB::table('role_permissions')
                ->where('role_id', $this->roleId)
                ->where('permission_id', $permissionId)
                ->exists()
        );

        $this->assertTrue(
            $authContext->hasPermission($user, 'create_exam')
        );

        $this->assertContains(
            'create_exam',
            $authContext->permissionNames($user)->all()
        );

        Auth::setUser($user);

        $response = app(ModulePermissionMiddleware::class)->handle(
            Request::create('/api/exams', 'GET'),
            fn () => response('allowed')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_module_permission_denies_when_no_effective_role_grants_capability(): void
    {
        $secondaryRoleId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $secondaryRoleId,
            'role_name' => 'Platform Security Observer',
            'school_id' => $this->schoolId,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $this->userId,
            'role_id' => $secondaryRoleId,
        ]);

        $user = User::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->userId);

        $authContext = app(AuthContextService::class);

        $this->assertFalse(
            $authContext->hasPermission($user, 'create_exam')
        );

        $this->assertNotContains(
            'create_exam',
            $authContext->permissionNames($user)->all()
        );

        Auth::setUser($user);

        $response = app(ModulePermissionMiddleware::class)->handle(
            Request::create('/api/exams', 'GET'),
            fn () => response('allowed')
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    private function permission(string $name): string
    {
        $existing = DB::table('permissions')
            ->where('permission_name', $name)
            ->value('id');

        if ($existing) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();

        DB::table('permissions')->insert([
            'id' => $id,
            'permission_name' => $name,
            'created_at' => now(),
        ]);

        return $id;
    }
}
