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

class PlatformOperationsMutationAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-operations-mutation-test-secret-',
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

    public function test_platform_mutation_routes_have_platform_boundary(): void
    {
        $expected = [
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/queue/failed/{job}/retry',
                'permission' => 'permission:retry_failed_jobs',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/queue/failed/{job}/forget',
                'permission' => 'permission:forget_failed_jobs',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/scheduler/tasks/{task}/run',
                'permission' => 'permission:run_allowlisted_scheduler_tasks',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/cache/preview-clear',
                'permission' => 'permission:clear_safe_cache_groups',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/cache/clear',
                'permission' => 'permission:clear_safe_cache_groups',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/diagnostics/run',
                'permission' => 'permission:run_operational_diagnostics',
            ],
            [
                'method' => 'PUT',
                'uri' => 'api/admin/operations/platform-settings',
                'permission' => 'permission:manage_platform_settings',
            ],
        ];

        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ($expected as $expectedRoute) {
            $route = $routes->first(
                fn ($route) => $route->uri() === $expectedRoute['uri']
                    && in_array(
                        $expectedRoute['method'],
                        $route->methods(),
                        true
                    )
            );

            $this->assertNotNull(
                $route,
                "Expected route [{$expectedRoute['method']} {$expectedRoute['uri']}] was not found."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'jwt',
                $middleware
            );

            $this->assertContains(
                'permission:access_platform_administration',
                $middleware,
                "Route [{$expectedRoute['uri']}] must require platform administration."
            );

            $this->assertContains(
                $expectedRoute['permission'],
                $middleware
            );

            $this->assertNotContains(
                'tenant',
                $middleware,
                "Route [{$expectedRoute['uri']}] must not use TenantMiddleware."
            );
        }
    }

    public function test_school_admin_is_denied_platform_mutations(): void
    {
        $schoolId = $this->school();

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $requests = [
            ['POST', '/api/admin/operations/queue/failed/fake-job/retry', []],
            ['POST', '/api/admin/operations/queue/failed/fake-job/forget', []],
            ['POST', '/api/admin/operations/scheduler/tasks/fake-task/run', []],
            ['POST', '/api/admin/operations/cache/preview-clear', []],
            ['POST', '/api/admin/operations/cache/clear', []],
            ['POST', '/api/admin/operations/diagnostics/run', []],
            ['PUT', '/api/admin/operations/platform-settings', []],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this
                ->withToken($token)
                ->json(
                    $method,
                    $uri,
                    $payload
                )
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_user_is_denied_platform_mutations(): void
    {
        $requests = [
            ['POST', '/api/admin/operations/queue/failed/fake-job/retry', []],
            ['POST', '/api/admin/operations/queue/failed/fake-job/forget', []],
            ['POST', '/api/admin/operations/scheduler/tasks/fake-task/run', []],
            ['POST', '/api/admin/operations/cache/preview-clear', []],
            ['POST', '/api/admin/operations/cache/clear', []],
            ['POST', '/api/admin/operations/diagnostics/run', []],
            ['PUT', '/api/admin/operations/platform-settings', []],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this
                ->json(
                    $method,
                    $uri,
                    $payload
                )
                ->assertUnauthorized();
        }
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
            'school_name' => 'Mutation Test School',
            'school_code' => 'MUT-'.Str::upper(
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
