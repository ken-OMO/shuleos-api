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

class PlatformDashboardAccessTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-dashboard-test-secret-',
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

    public function test_school_less_platform_owner_can_access_platform_dashboard(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson(
                '/api/admin/dashboard/platform'
            )
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this
            ->getJson(
                '/api/admin/dashboard/platform'
            )
            ->assertUnauthorized();
    }

    public function test_school_admin_cannot_access_platform_dashboard(): void
    {
        $schoolId = $this->school();

        $user = User::create([
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

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson(
                '/api/admin/dashboard/platform'
            )
            ->assertForbidden();
    }

    public function test_school_bound_platform_owner_is_rejected(): void
    {
        $schoolId = $this->school();

        $user = User::create([
            'id' => (string) Str::uuid(),

            'school_id' => $schoolId,

            'role_id' => $this->platformRoleId,

            'username' => 'invalid-platform-owner-'
                .Str::lower(
                    Str::random(6)
                ),

            'password_hash' => Hash::make(
                'Correct#Password99'
            ),

            'email' => 'invalid-platform-owner-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',

            'first_name' => 'Invalid',

            'last_name' => 'Owner',

            'active' => true,

            'first_login' => false,

            'auth_generation' => 1,

            'is_deleted' => false,
        ]);

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->getJson(
                '/api/admin/dashboard/platform'
            )
            ->assertForbidden();
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

    private function school(): string
    {
        $schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,

            'school_name' => 'Dashboard Test School',

            'school_code' => 'DASH-'
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
        string $name
    ): string {
        $roleId = DB::table('roles')
            ->where(
                'role_name',
                $name
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
                "Required system role [{$name}] was not found."
            );
        }

        return (string) $roleId;
    }
}
