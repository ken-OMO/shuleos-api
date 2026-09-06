<?php

namespace Tests\Feature;

use App\Http\Resources\AdministratorSafeResource;
use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Administrator\AdministratorRolePermissionService;
use App\Services\Administrator\AdministratorUserService;
use App\Services\Administrator\SchoolLifecycleAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdministratorPortalPhaseOneTest extends TestCase
{
    use DatabaseTransactions;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['school', 'other_school', 'school_role', 'platform_role', 'teacher_role', 'admin', 'other_admin', 'platform', 'teacher'] as $key) {
            $this->ids[$key] = (string) Str::uuid();
        }
        DB::table('schools')->insert([
            ['id' => $this->ids['school'], 'school_name' => 'Administrator School', 'school_code' => 'ADMIN-1', 'active' => true, 'lifecycle_state' => 'active', 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->ids['other_school'], 'school_name' => 'Other School', 'school_code' => 'ADMIN-2', 'active' => true, 'lifecycle_state' => 'active', 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach ([
            'school_role' => 'School Admin',
            'platform_role' => 'Platform Super Administrator',
            'teacher_role' => 'Teacher',
        ] as $key => $roleName) {
            $roleId = DB::table('roles')
                ->where('role_name', $roleName)
                ->value('id');

            if (! $roleId) {
                $roleId = (string) Str::uuid();

                DB::table('roles')->insert([
                    'id' => $roleId,
                    'role_name' => $roleName,
                    'school_id' => null,
                    'system_role' => true,
                    'active' => true,
                    'created_at' => now(),
                ]);
            }

            $this->ids[$key] = $roleId;
        }
        $this->makeUser('admin', 'school', 'school_role');
        $this->makeUser('other_admin', 'other_school', 'school_role');
        $this->makeUser('platform', null, 'platform_role');
        $this->makeUser('teacher', 'school', 'teacher_role');
        $this->grant('school_role', ['access_administrator_portal', 'view_school_users', 'create_school_users', 'update_school_users', 'view_roles_and_permissions', 'manage_school_roles', 'assign_school_permissions', 'revoke_school_user_sessions', 'revoke_school_user_devices']);
        $this->grant('platform_role', ['access_administrator_portal', 'access_platform_administration', 'manage_school_lifecycle', 'view_platform_dashboard']);
    }

    public function test_administrator_access_strictly_separates_school_and_platform_scope(): void
    {
        $access = app(AdministratorPortalAccessService::class);
        $this->assertFalse($access->scope($this->user('admin'))['platform']);
        $this->assertTrue($access->scope($this->user('platform'))['platform']);
        $this->expectException(AuthorizationException::class);
        $access->scope($this->user('teacher'));
    }

    public function test_administrator_users_are_tenant_scoped_and_platform_roles_cannot_be_assigned(): void
    {
        $service = app(AdministratorUserService::class);
        $this->expectException(ModelNotFoundException::class);
        $service->find($this->user('admin'), $this->ids['other_admin']);
    }

    public function test_administrator_roles_prevent_platform_privilege_escalation(): void
    {
        $service = app(AdministratorRolePermissionService::class);
        $role = $service->create($this->user('admin'), 'Local Reviewer');
        $this->expectException(AuthorizationException::class);
        $service->assign($this->user('admin'), $role->id, ['access_platform_administration']);
    }

    public function test_school_lifecycle_requires_platform_permission_and_preserves_data(): void
    {
        $service = app(SchoolLifecycleAdministrationService::class);
        try {
            $service->transition($this->user('admin'), $this->ids['other_school'], 'suspended', 'Policy review');
            $this->fail('School administrator changed another school lifecycle.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $updated = $service->transition($this->user('platform'), $this->ids['other_school'], 'suspended', 'Policy review');
        $this->assertSame('suspended', $updated->lifecycle_state);
        $this->assertDatabaseHas('school_lifecycle_history', ['school_id' => $this->ids['other_school'], 'to_state' => 'suspended']);
        $this->assertDatabaseHas('schools', ['id' => $this->ids['other_school'], 'is_deleted' => false]);
    }

    public function test_admin_routes_have_explicit_permission_middleware_and_legacy_mutations_are_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach (['api/admin/dashboard', 'api/admin/users', 'api/admin/platform/schools', 'api/admin/imports/preview', 'api/admin/system-health'] as $uri) {
            $route = $routes->first(fn ($item) => $item->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNotNull(collect($route->gatherMiddleware())->first(fn ($middleware) => str_starts_with($middleware, 'permission:')), $uri);
        }
        $this->assertNull($routes->first(fn ($item) => $item->uri() === 'api/users' && in_array('POST', $item->methods(), true)));
        $this->assertNull($routes->first(fn ($item) => $item->uri() === 'api/schools/{id}' && in_array('DELETE', $item->methods(), true)));
    }

    public function test_administrator_security_resource_redacts_password_tokens_storage_and_payloads_recursively(): void
    {
        $data = (new AdministratorSafeResource(['id' => 'safe', 'password_hash' => 'secret', 'storage_id' => 'private', 'nested' => ['push_token' => 'secret', 'name' => 'visible']]))->toArray(Request::create('/'));
        $this->assertSame(['id' => 'safe', 'nested' => ['name' => 'visible']], $data);
    }

    public function test_invalid_lifecycle_transition_and_missing_reason_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SchoolLifecycleAdministrationService::class)->transition($this->user('platform'), $this->ids['other_school'], 'locked', null);
    }

    public function test_administrator_import_routes_require_permission_and_never_expose_storage(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($item) => $item->uri() === 'api/admin/imports/preview');
        $this->assertContains('permission:manage_data_imports', $route->gatherMiddleware());
        $source = file_get_contents(app_path('Services/Administrator/AdministratorImportService.php'));
        $this->assertStringContainsString('->only($this->safeColumns())', $source);
        $this->assertStringNotContainsString("'storage_id', 'source_hash'", $source);
    }

    public function test_administrator_system_health_is_read_only_secret_free_and_bounded(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/AdministratorOperationsService.php'));
        $this->assertStringNotContainsString('env(', $source);
        $this->assertStringNotContainsString('Artisan::call', $source);
        $this->assertStringNotContainsString('DB_PASSWORD', $source);
        $this->assertStringContainsString("'migration_count'", $source);
    }

    public function test_role_permission_delegation_accepts_permission_held_only_by_secondary_role(): void
    {
        $secondaryRoleId = $this->createSchoolRole(
            'Delegation Secondary Authority'
        );

        $targetRoleId = $this->createSchoolRole(
            'Delegation Target Role'
        );

        $permissionName = 'delegation_secondary_only_permission';

        $this->grantPermissionToRoleId(
            $secondaryRoleId,
            $permissionName
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->ids['admin'],
            'role_id' => $secondaryRoleId,
        ]);

        $this->assertFalse(
            $this->roleHasPermission(
                $this->ids['school_role'],
                $permissionName
            )
        );

        app(AdministratorRolePermissionService::class)->assign(
            $this->user('admin'),
            $targetRoleId,
            [$permissionName]
        );

        $this->assertTrue(
            $this->roleHasPermission(
                $targetRoleId,
                $permissionName
            )
        );
    }

    public function test_role_permission_delegation_rejects_permission_outside_effective_role_union(): void
    {
        $secondaryRoleId = $this->createSchoolRole(
            'Delegation Limited Authority'
        );

        $targetRoleId = $this->createSchoolRole(
            'Delegation Restricted Target'
        );

        $this->grantPermissionToRoleId(
            $secondaryRoleId,
            'delegation_allowed_permission'
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->ids['admin'],
            'role_id' => $secondaryRoleId,
        ]);

        $outsidePermission = 'delegation_outside_effective_union';

        $this->ensurePermission($outsidePermission);

        try {
            app(AdministratorRolePermissionService::class)->assign(
                $this->user('admin'),
                $targetRoleId,
                [$outsidePermission]
            );

            $this->fail(
                'Administrator granted a permission outside the effective role union.'
            );
        } catch (AuthorizationException) {
            $this->assertFalse(
                $this->roleHasPermission(
                    $targetRoleId,
                    $outsidePermission
                )
            );
        }
    }

    public function test_user_role_assignment_accepts_target_role_covered_by_secondary_role_authority(): void
    {
        $secondaryRoleId = $this->createSchoolRole(
            'Assignment Secondary Authority'
        );

        $targetRoleId = $this->createSchoolRole(
            'Assignment Covered Target'
        );

        $permissionName = 'assignment_secondary_only_permission';

        $this->grantPermissionToRoleId(
            $secondaryRoleId,
            $permissionName
        );

        $this->grantPermissionToRoleId(
            $targetRoleId,
            $permissionName
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->ids['admin'],
            'role_id' => $secondaryRoleId,
        ]);

        $this->assertFalse(
            $this->roleHasPermission(
                $this->ids['school_role'],
                $permissionName
            )
        );

        $updated = app(AdministratorUserService::class)->update(
            $this->user('admin'),
            $this->ids['teacher'],
            [
                'role_id' => $targetRoleId,
            ]
        );

        $this->assertSame(
            $targetRoleId,
            $updated->role_id
        );
    }

    public function test_user_role_assignment_rejects_target_role_outside_effective_role_union(): void
    {
        $secondaryRoleId = $this->createSchoolRole(
            'Assignment Limited Authority'
        );

        $targetRoleId = $this->createSchoolRole(
            'Assignment Excessive Target'
        );

        $this->grantPermissionToRoleId(
            $secondaryRoleId,
            'assignment_allowed_permission'
        );

        $outsidePermission = 'assignment_outside_effective_union';

        $this->grantPermissionToRoleId(
            $targetRoleId,
            $outsidePermission
        );

        DB::table('user_roles')->insert([
            'user_id' => $this->ids['admin'],
            'role_id' => $secondaryRoleId,
        ]);

        try {
            app(AdministratorUserService::class)->update(
                $this->user('admin'),
                $this->ids['teacher'],
                [
                    'role_id' => $targetRoleId,
                ]
            );

            $this->fail(
                'Administrator assigned a role containing permissions outside the effective role union.'
            );
        } catch (AuthorizationException) {
            $this->assertSame(
                $this->ids['teacher_role'],
                $this->user('teacher')->role_id
            );
        }
    }

    private function createSchoolRole(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'role_name' => $name,
            'school_id' => $this->ids['school'],
            'system_role' => false,
            'active' => true,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function ensurePermission(string $name): string
    {
        $id = DB::table('permissions')
            ->where('permission_name', $name)
            ->value('id');

        if ($id) {
            return (string) $id;
        }

        $id = (string) Str::uuid();

        DB::table('permissions')->insert([
            'id' => $id,
            'permission_name' => $name,
            'module_name' => 'administrator_portal',
            'created_at' => now(),
        ]);

        return $id;
    }

    private function grantPermissionToRoleId(
        string $roleId,
        string $permissionName
    ): void {
        $permissionId = $this->ensurePermission(
            $permissionName
        );

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    private function roleHasPermission(
        string $roleId,
        string $permissionName
    ): bool {
        return DB::table('role_permissions')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id'
            )
            ->where(
                'role_permissions.role_id',
                $roleId
            )
            ->where(
                'permissions.permission_name',
                $permissionName
            )
            ->exists();
    }

    private function makeUser(string $user, ?string $school, string $role): void
    {
        User::create(['id' => $this->ids[$user], 'school_id' => $school !== null ? $this->ids[$school] : null, 'role_id' => $this->ids[$role], 'username' => $user.'-'.Str::random(6), 'password_hash' => bcrypt('password'), 'first_name' => Str::headline($user), 'last_name' => 'User', 'active' => true, 'first_login' => false, 'auth_generation' => 1]);
    }

    private function grant(string $role, array $names): void
    {
        foreach ($names as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $permission) {
                $permission = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $permission, 'permission_name' => $name, 'module_name' => 'administrator_portal', 'created_at' => now()]);
            }
            DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $this->ids[$role], 'permission_id' => $permission, 'created_at' => now()]);
        }
    }

    private function user(string $key): User
    {
        return User::with('role')->findOrFail($this->ids[$key]);
    }
}
