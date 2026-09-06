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

class PlatformSchoolDirectoryAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-school-directory-test-secret-',
                3
            ),
            'jwt.ttl' => 60,
        ]);

        $this->platformRoleId = $this->systemRole(
            'Platform Owner'
        );

        $this->schoolAdminRoleId = $this->systemRole(
            'School Admin'
        );
    }

    public function test_platform_owner_can_list_schools(): void
    {
        $this->school();

        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson('/api/schools')
            ->assertOk();
    }

    public function test_platform_owner_can_view_school(): void
    {
        $schoolId = $this->school();

        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson(
                '/api/schools/'.$schoolId
            )
            ->assertOk();
    }

    public function test_school_admin_cannot_list_global_schools(): void
    {
        $schoolId = $this->school();

        $user = $this->schoolAdmin(
            $schoolId
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson('/api/schools')
            ->assertForbidden();
    }

    public function test_school_admin_cannot_view_global_school(): void
    {
        $schoolId = $this->school();

        $user = $this->schoolAdmin(
            $schoolId
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson(
                '/api/schools/'.$schoolId
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $schoolId = $this->school();

        $this
            ->getJson('/api/schools')
            ->assertUnauthorized();

        $this
            ->getJson(
                '/api/schools/'.$schoolId
            )
            ->assertUnauthorized();
    }

    public function test_global_school_routes_require_platform_permission_and_no_tenant_middleware(): void
    {
        $expectedUris = [
            'api/schools',
            'api/schools/{id}',
        ];

        foreach ($expectedUris as $uri) {
            $route = collect(
                app('router')->getRoutes()->getRoutes()
            )->first(
                fn ($candidate) => $candidate->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "Expected route [{$uri}] was not found."
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

            $this->assertNotContains(
                'tenant',
                $middleware,
                "Route [{$uri}] must not use TenantMiddleware."
            );
        }
    }

    private function platformOwner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $this->platformRoleId,
            'username' => 'platform-owner-'
                .Str::lower(
                    Str::random(6)
                ),
            'password_hash' => Hash::make(
                'Correct#Password99'
            ),
            'email' => 'platform-owner-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'active' => true,
            'first_login' => false,
            'email_verified_at' => now(),
            'activated_at' => now(),
            'mfa_enabled' => true,
            'auth_generation' => 1,
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
                .Str::lower(
                    Str::random(6)
                ),
            'password_hash' => Hash::make(
                'Correct#Password99'
            ),
            'email' => 'school-admin-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',
            'first_name' => 'School',
            'last_name' => 'Admin',
            'active' => true,
            'first_login' => false,
            'auth_generation' => 1,
            'is_deleted' => false,
        ]);
    }

    private function school(): string
    {
        $schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,
            'school_name' => 'Directory Test School',
            'school_code' => 'DIR-'
                .Str::upper(
                    Str::random(5)
                ),
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
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
