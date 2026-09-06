<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Tests\TestCase;

class PlatformSchoolOnboardingSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-school-onboarding-test-secret-',
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

    public function test_platform_owner_can_onboard_school_with_server_generated_identity_and_credentials(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'initial-admin-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'school_name' => 'Onboarding Test School',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Initial',
                        'last_name' => 'Administrator',
                        'email' => $adminEmail,
                    ],
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'success',
                true
            );

        $schoolCode = $response->json(
            'data.school.school_code'
        );

        $loginPrefix = $response->json(
            'data.school.login_prefix'
        );

        $username = $response->json(
            'data.initial_admin.username'
        );

        $temporaryPassword = $response->json(
            'data.initial_admin.temporary_password'
        );

        $this->assertIsString($schoolCode);
        $this->assertNotSame('', trim($schoolCode));

        $this->assertIsString($loginPrefix);
        $this->assertNotSame('', trim($loginPrefix));

        $this->assertIsString($username);
        $this->assertNotSame('', trim($username));

        $this->assertIsString($temporaryPassword);
        $this->assertGreaterThanOrEqual(
            16,
            strlen($temporaryPassword)
        );

        $school = DB::table('schools')
            ->where(
                'school_code',
                $schoolCode
            )
            ->first();

        $this->assertNotNull(
            $school
        );

        $this->assertSame(
            'Onboarding Test School',
            $school->school_name
        );

        $this->assertSame(
            'onboarding',
            $school->lifecycle_state
        );

        $this->assertFalse(
            (bool) $school->is_deleted
        );

        $admin = DB::table('users')
            ->where(
                'email',
                $adminEmail
            )
            ->first();

        $this->assertNotNull(
            $admin
        );

        $this->assertSame(
            (string) $school->id,
            (string) $admin->school_id
        );

        $this->assertSame(
            $this->schoolAdminRoleId,
            (string) $admin->role_id
        );

        $this->assertSame(
            $username,
            $admin->username
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $admin->password_hash
            )
        );

        $this->assertNotSame(
            $temporaryPassword,
            $admin->password_hash
        );

        $this->assertTrue(
            (bool) $admin->first_login
        );

        $this->assertTrue(
            (bool) $admin->temporary_password
        );

        $this->assertNotNull(
            $admin->temporary_password_expires_at
        );

        $this->assertNotNull(
            $admin->force_password_reset_at
        );
    }

    public function test_client_cannot_supply_authoritative_school_or_admin_identity_fields(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $otherSchoolId = $this->school();

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'school_name' => 'Authority Injection School',

                    'school_code' => 'CLIENT-CONTROLLED',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Initial',

                        'last_name' => 'Administrator',

                        'email' => 'authority-injection-'
                            .Str::lower(
                                Str::random(6)
                            )
                            .'@example.test',

                        'username' => 'platform.owner',

                        'temporary_password' => 'ClientChosen#Password99',

                        'role_id' => $this->platformRoleId,

                        'school_id' => $otherSchoolId,
                    ],
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'school_code',
                'admin.username',
                'admin.temporary_password',
                'admin.role_id',
                'admin.school_id',
            ]);
    }

    public function test_generated_temporary_password_is_not_persisted_or_audited(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'credential-security-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'school_name' => 'Credential Security School',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Credential',

                        'last_name' => 'Administrator',

                        'email' => $adminEmail,
                    ],
                ]
            )
            ->assertCreated();

        $temporaryPassword = $response->json(
            'data.initial_admin.temporary_password'
        );

        $schoolId = $response->json(
            'data.school.id'
        );

        $admin = DB::table('users')
            ->where(
                'email',
                $adminEmail
            )
            ->first();

        $this->assertNotNull(
            $admin
        );

        $this->assertNotSame(
            $temporaryPassword,
            $admin->password_hash
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $admin->password_hash
            )
        );

        $audit = DB::table('audit_logs')
            ->where(
                'action',
                'platform_school_onboarded'
            )
            ->where(
                'school_id',
                $schoolId
            )
            ->latest(
                'created_at'
            )
            ->first();

        $this->assertNotNull(
            $audit
        );

        $this->assertStringNotContainsString(
            $temporaryPassword,
            json_encode(
                $audit,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_onboarding_rolls_back_school_and_admin_when_finalization_fails(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'rollback-admin-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $schoolName = 'Atomic Rollback School';

        $this->mock(
            AdministratorAuditService::class,
            function ($mock) {
                $mock
                    ->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure.'
                        )
                    );
            }
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'school_name' => $schoolName,

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Rollback',

                        'last_name' => 'Administrator',

                        'email' => $adminEmail,
                    ],
                ]
            );

        $response->assertStatus(
            500
        );

        $this->assertDatabaseMissing(
            'schools',
            [
                'school_name' => $schoolName,
            ]
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => $adminEmail,
            ]
        );
    }

    public function test_platform_user_with_lifecycle_permission_but_without_onboarding_permission_cannot_onboard_school(): void
    {
        $user = $this->platformOwner();

        $onboardPermissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'onboard_schools'
            )
            ->value('id');

        $lifecyclePermissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_school_lifecycle'
            )
            ->value('id');

        $this->assertNotNull(
            $onboardPermissionId
        );

        $this->assertNotNull(
            $lifecyclePermissionId
        );

        $this->assertTrue(
            DB::table('role_permissions')
                ->where(
                    'role_id',
                    $this->platformRoleId
                )
                ->where(
                    'permission_id',
                    $lifecyclePermissionId
                )
                ->exists()
        );

        DB::table('role_permissions')
            ->where(
                'role_id',
                $this->platformRoleId
            )
            ->where(
                'permission_id',
                $onboardPermissionId
            )
            ->delete();

        $this->assertFalse(
            DB::table('role_permissions')
                ->where(
                    'role_id',
                    $this->platformRoleId
                )
                ->where(
                    'permission_id',
                    $onboardPermissionId
                )
                ->exists()
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'school_name' => 'Lifecycle Only School',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Lifecycle',

                        'last_name' => 'Administrator',

                        'email' => 'lifecycle-only-'
                            .Str::lower(
                                Str::random(6)
                            )
                            .'@example.test',
                    ],
                ]
            )
            ->assertForbidden();
    }

    public function test_school_admin_cannot_onboard_school(): void
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
            ->postJson(
                '/api/admin/platform/schools',
                $this->validPayload()
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_onboard_school(): void
    {
        $this
            ->postJson(
                '/api/admin/platform/schools',
                $this->validPayload()
            )
            ->assertUnauthorized();
    }

    public function test_school_onboarding_route_requires_dedicated_onboarding_permission_and_no_tenant_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(
                fn ($route) => in_array('POST', $route->methods(), true) &&
                    $route->uri() === 'api/admin/platform/schools'
            );

        $this->assertNotNull(
            $route,
            'Expected POST onboarding route [api/admin/platform/schools] was not found.'
        );

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'jwt',
            $middleware
        );

        $this->assertContains(
            'permission:access_platform_administration',
            $middleware
        );

        $this->assertContains(
            'permission:onboard_schools',
            $middleware
        );

        $this->assertNotContains(
            'permission:manage_school_lifecycle',
            $middleware
        );

        $this->assertFalse(
            collect($middleware)->contains(
                fn ($name) => str_contains((string) $name, 'tenant') ||
                    str_contains((string) $name, 'school.subscription')
            ),
            'Platform school onboarding must remain outside tenant/subscription middleware.'
        );
    }

    private function validPayload(): array
    {
        return [
            'school_name' => 'Unauthorized Onboarding School',

            'timezone' => 'Africa/Nairobi',

            'locale' => 'en',

            'admin' => [
                'first_name' => 'Initial',

                'last_name' => 'Administrator',

                'email' => 'blocked-admin-'
                    .Str::lower(
                        Str::random(6)
                    )
                    .'@example.test',
            ],
        ];
    }

    private function platformOwner(): User
    {
        $this->grantPermission(
            $this->platformRoleId,
            'onboard_schools'
        );

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

            'school_name' => 'Existing Onboarding Test School',

            'school_code' => 'EXIST-'
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

    private function grantPermission(
        string $roleId,
        string $permissionName
    ): void {
        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                $permissionName
            )
            ->value('id');

        if (! $permissionId) {
            throw new RuntimeException(
                "Required permission [{$permissionName}] was not found."
            );
        }

        DB::table('role_permissions')
            ->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
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
