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

class PlatformOperationsDeepReadAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-operations-deep-read-test-secret-',
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

    public function test_platform_owner_can_access_deep_read_only_platform_operations(): void
    {
        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        foreach ($this->routesForStatusChecks() as $uri) {
            $response = $this
                ->withToken($token)
                ->getJson($uri);

            $this->assertNotSame(
                401,
                $response->status(),
                "Platform Owner was unauthenticated for [{$uri}]."
            );

            $this->assertNotSame(
                403,
                $response->status(),
                "Platform Owner was forbidden for [{$uri}]."
            );
        }
    }

    public function test_school_admin_cannot_access_deep_platform_operations(): void
    {
        $schoolId = $this->school();

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        foreach ($this->routesForStatusChecks() as $uri) {
            $this
                ->withToken($token)
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_user_cannot_access_deep_platform_operations(): void
    {
        foreach ($this->routesForStatusChecks() as $uri) {
            $this
                ->getJson($uri)
                ->assertUnauthorized();
        }
    }

    public function test_deep_platform_read_routes_require_platform_scope_and_no_tenant_middleware(): void
    {
        $expected = [
            'api/admin/operations/queue/jobs' => 'permission:view_queue_operations',

            'api/admin/operations/queue/failed' => 'permission:view_queue_operations',

            'api/admin/operations/queue/failed/{job}' => 'permission:view_queue_operations',

            'api/admin/operations/scheduler/tasks' => 'permission:view_scheduler_operations',

            'api/admin/operations/scheduler/tasks/{task}' => 'permission:view_scheduler_operations',

            'api/admin/operations/logs/{log}' => 'permission:view_application_logs',

            'api/admin/operations/logs/{log}/entries' => 'permission:view_application_logs',

            'api/admin/operations/storage/disks' => 'permission:view_storage_operations',

            'api/admin/operations/storage/quarantine' => 'permission:view_storage_operations',

            'api/admin/operations/storage/orphans' => 'permission:view_storage_operations',

            'api/admin/operations/backups/{backup}' => 'permission:view_backup_operations',

            'api/admin/operations/restores/{restore}' => 'permission:view_restore_operations',

            'api/admin/operations/diagnostics/{run}' => 'permission:view_operational_diagnostics',

            'api/admin/operations/releases/current' => 'permission:view_release_metadata',
        ];

        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ($expected as $uri => $permission) {
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

    private function routesForStatusChecks(): array
    {
        return [
            '/api/admin/operations/queue/jobs',
            '/api/admin/operations/queue/failed',
            '/api/admin/operations/queue/failed/non-existent-job',
            '/api/admin/operations/scheduler/tasks',
            '/api/admin/operations/scheduler/tasks/non-existent-task',
            '/api/admin/operations/logs/non-existent-log',
            '/api/admin/operations/logs/non-existent-log/entries',
            '/api/admin/operations/storage/disks',
            '/api/admin/operations/storage/quarantine',
            '/api/admin/operations/storage/orphans',
            '/api/admin/operations/backups/non-existent-backup',
            '/api/admin/operations/restores/non-existent-restore',
            '/api/admin/operations/diagnostics/non-existent-run',
            '/api/admin/operations/releases/current',
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
            'school_name' => 'Deep Operations Test School',
            'school_code' => 'DOPS-'.Str::upper(
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
