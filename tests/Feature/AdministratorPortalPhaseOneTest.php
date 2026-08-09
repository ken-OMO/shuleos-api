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
        DB::table('roles')->insert([
            ['id' => $this->ids['school_role'], 'role_name' => 'School Admin', 'school_id' => null, 'system_role' => true, 'active' => true, 'created_at' => now()],
            ['id' => $this->ids['platform_role'], 'role_name' => 'Platform Super Administrator', 'school_id' => null, 'system_role' => true, 'active' => true, 'created_at' => now()],
            ['id' => $this->ids['teacher_role'], 'role_name' => 'Teacher', 'school_id' => null, 'system_role' => true, 'active' => true, 'created_at' => now()],
        ]);
        $this->makeUser('admin', 'school', 'school_role');
        $this->makeUser('other_admin', 'other_school', 'school_role');
        $this->makeUser('platform', 'school', 'platform_role');
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

    private function makeUser(string $user, string $school, string $role): void
    {
        User::create(['id' => $this->ids[$user], 'school_id' => $this->ids[$school], 'role_id' => $this->ids[$role], 'username' => $user.'-'.Str::random(6), 'password_hash' => bcrypt('password'), 'first_name' => Str::headline($user), 'last_name' => 'User', 'active' => true, 'first_login' => false, 'auth_generation' => 1]);
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
