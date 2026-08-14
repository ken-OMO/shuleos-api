<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Tests\TestCase;

class PlatformOperationsAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-operations-test-secret-',
                3
            ),
        ]);

        $this->platformRoleId = $this->systemRole(
            'Platform Owner'
        );

        $this->schoolAdminRoleId = $this->systemRole(
            'School Admin'
        );
    }

    public function test_platform_owner_can_access_read_only_platform_operations(): void
    {
        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        foreach ($this->platformReadRoutes() as $uri) {
            $this
                ->withToken($token)
                ->getJson($uri)
                ->assertOk();
        }
    }

    public function test_school_admin_cannot_access_platform_operations(): void
    {
        $schoolId = $this->school();

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        foreach ($this->platformReadRoutes() as $uri) {
            $this
                ->withToken($token)
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_user_cannot_access_platform_operations(): void
    {
        foreach ($this->platformReadRoutes() as $uri) {
            $this
                ->getJson($uri)
                ->assertUnauthorized();
        }
    }

    public function test_platform_operation_routes_require_platform_scope_and_no_tenant_middleware(): void
    {
        $expectedPermissions = [
            'api/admin/operations/queue' => 'permission:view_queue_operations',

            'api/admin/operations/scheduler' => 'permission:view_scheduler_operations',

            'api/admin/operations/cache' => 'permission:view_cache_operations',

            'api/admin/operations/logs' => 'permission:view_application_logs',

            'api/admin/operations/storage' => 'permission:view_storage_operations',

            'api/admin/operations/backups' => 'permission:view_backup_operations',

            'api/admin/operations/restores' => 'permission:view_restore_operations',

            'api/admin/operations/diagnostics' => 'permission:view_operational_diagnostics',

            'api/admin/operations/releases' => 'permission:view_release_metadata',

            'api/admin/operations/platform-settings' => 'permission:manage_platform_settings',

            'api/admin/operations/disaster-recovery' => 'permission:view_disaster_recovery_readiness',
        ];

        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ($expectedPermissions as $uri => $permission) {
            $route = $routes->first(
                fn ($route) => $route->uri() === $uri
                    && in_array(
                        'GET',
                        $route->methods(),
                        true
                    )
            );

            $this->assertNotNull(
                $route,
                "Expected GET route [{$uri}] was not found."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'jwt',
                $middleware,
                "Route [{$uri}] must require JWT authentication."
            );

            $this->assertContains(
                'permission:access_platform_administration',
                $middleware,
                "Route [{$uri}] must require platform administration access."
            );

            $this->assertContains(
                $permission,
                $middleware,
                "Route [{$uri}] must retain [{$permission}]."
            );

            $this->assertNotContains(
                'tenant',
                $middleware,
                "Route [{$uri}] must not use TenantMiddleware."
            );
        }
    }

    private function platformReadRoutes(): array
    {
        return [
            '/api/admin/operations/queue',
            '/api/admin/operations/scheduler',
            '/api/admin/operations/cache',
            '/api/admin/operations/logs',
            '/api/admin/operations/storage',
            '/api/admin/operations/backups',
            '/api/admin/operations/restores',
            '/api/admin/operations/diagnostics',
            '/api/admin/operations/releases',
            '/api/admin/operations/platform-settings',
            '/api/admin/operations/disaster-recovery',
        ];
    }

    private function platformOwner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $this->platformRoleId,
            'username' => 'platform-owner-'
                .Str::lower(Str::random(6)),
            'password_hash' => Hash::make(
                'Correct#Password99'
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    private function schoolAdmin(
        string $schoolId
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'role_id' => $this->schoolAdminRoleId,
            'username' => 'school-admin-'
                .Str::lower(Str::random(6)),
            'password_hash' => Hash::make(
                'Correct#Password99'
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    private function school(): string
    {
        $schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,
            'school_name' => 'Operations Test School',
            'school_code' => 'OPS-'.Str::upper(
                Str::random(6)
            ),
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $schoolId;
    }

    private function systemRole(
        string $roleName
    ): string {
        $roleId = DB::table('roles')
            ->where(
                'role_name',
                $roleName
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
            ->value('id');

        if (! $roleId) {
            throw new RuntimeException(
                "Required system role [{$roleName}] was not found."
            );
        }

        return (string) $roleId;
    }
}
