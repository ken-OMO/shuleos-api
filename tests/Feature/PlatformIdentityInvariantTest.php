<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Auth\AuthContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class PlatformIdentityInvariantTest extends TestCase
{
    use DatabaseTransactions;

    private string $schoolId;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $this->schoolId,
            'school_name' => 'Platform Boundary School',
            'school_code' => 'PBS-001',
            'short_name' => 'PBS',
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->platformRoleId = $this->systemRoleId(
            'Platform Owner'
        );

        $this->schoolAdminRoleId = $this->systemRoleId(
            'School Admin'
        );
    }

    public function test_school_less_platform_owner_has_platform_scope(): void
    {
        $user = $this->makeUser(
            null,
            $this->platformRoleId,
            'platform-owner'
        );

        $scope = app(
            AdministratorPortalAccessService::class
        )->scope($user);

        $this->assertSame(
            'platform',
            $scope['scope']
        );

        $this->assertTrue(
            $scope['platform']
        );

        $this->assertNull(
            $scope['school_id']
        );
    }

    public function test_auth_me_returns_platform_contract_for_school_less_platform_owner(): void
    {
        config([
            'jwt.secret' => str_repeat(
                'platform-identity-test-secret-',
                3
            ),
            'jwt.ttl' => 60,
        ]);

        $user = $this->makeUser(
            null,
            $this->platformRoleId,
            'platform-me'
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $response = $this
            ->withToken($token)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.user.scope',
                'platform'
            )
            ->assertJsonPath(
                'data.user.school_id',
                null
            )
            ->assertJsonPath(
                'data.user.school',
                null
            )
            ->assertJsonPath(
                'data.user.roles.0',
                'Platform Owner'
            )
            ->assertJsonPath(
                'user.scope',
                'platform'
            );
    }

    public function test_platform_owner_attached_to_school_is_denied(): void
    {
        $user = $this->makeUser(
            $this->schoolId,
            $this->platformRoleId,
            'invalid-platform-owner'
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(
            AdministratorPortalAccessService::class
        )->scope($user);
    }

    public function test_auth_context_rejects_platform_owner_attached_to_school(): void
    {
        $user = $this->makeUser(
            $this->schoolId,
            $this->platformRoleId,
            'invalid-platform-context'
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(
            AuthContextService::class
        )->resolve($user);
    }

    public function test_school_admin_with_school_has_school_scope(): void
    {
        $user = $this->makeUser(
            $this->schoolId,
            $this->schoolAdminRoleId,
            'school-admin'
        );

        $scope = app(
            AdministratorPortalAccessService::class
        )->scope($user);

        $this->assertSame(
            'school',
            $scope['scope']
        );

        $this->assertFalse(
            $scope['platform']
        );

        $this->assertSame(
            $this->schoolId,
            (string) $scope['school_id']
        );
    }

    public function test_school_less_school_admin_is_denied(): void
    {
        $user = $this->makeUser(
            null,
            $this->schoolAdminRoleId,
            'invalid-school-admin'
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(
            AdministratorPortalAccessService::class
        )->scope($user);
    }

    public function test_auth_context_resolves_school_less_platform_owner(): void
    {
        $user = $this->makeUser(
            null,
            $this->platformRoleId,
            'platform-context'
        );

        $context = app(
            AuthContextService::class
        )->resolve($user);

        $this->assertSame(
            'platform',
            $context['scope']
        );

        $this->assertNull(
            $context['school_id']
        );

        $this->assertNull(
            $context['school']
        );

        $this->assertContains(
            'Platform Owner',
            $context['roles']
        );

        $this->assertContains(
            'access_platform_administration',
            $context['permissions']
        );
    }

    private function makeUser(
        ?string $schoolId,
        string $roleId,
        string $username
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'role_id' => $roleId,
            'username' => $username.'-'.Str::lower(
                Str::random(6)
            ),
            'password_hash' => Hash::make(
                'correct-password'
            ),
            'email' => $username.'@example.test',
            'first_name' => 'Platform',
            'last_name' => 'User',
            'active' => true,
            'first_login' => false,
            'auth_generation' => 1,
            'is_deleted' => false,
        ]);
    }

    private function systemRoleId(
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
            throw new \RuntimeException(
                "Required system role [{$roleName}] was not found."
            );
        }

        return (string) $roleId;
    }
}
