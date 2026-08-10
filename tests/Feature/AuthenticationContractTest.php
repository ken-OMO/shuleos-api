<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationContractTest extends TestCase
{
    use DatabaseTransactions;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => str_repeat('authentication-contract-test-secret-', 3),
            'jwt.ttl' => 60,
            'jwt.blacklist_enabled' => true,
        ]);
        foreach (['school', 'other_school', 'user', 'primary_role', 'additional_role', 'other_role'] as $key) {
            $this->ids[$key] = (string) Str::uuid();
        }

        DB::table('schools')->insert([
            [
                'id' => $this->ids['school'], 'school_name' => 'Contract School', 'school_code' => 'AUTH-1',
                'short_name' => 'Contract', 'active' => true, 'is_deleted' => false, 'lifecycle_state' => 'active',
                'timezone' => 'Africa/Nairobi', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => $this->ids['other_school'], 'school_name' => 'Unrelated School', 'school_code' => 'AUTH-2',
                'short_name' => null, 'active' => true, 'is_deleted' => false, 'lifecycle_state' => 'active',
                'timezone' => 'Africa/Nairobi', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        $teacherRoleId = DB::table('roles')
            ->where('role_name', 'Teacher')
            ->value('id');

        if (! $teacherRoleId) {
            throw new \RuntimeException('Required migrated Teacher role was not found.');
        }

        $this->ids['primary_role'] = $teacherRoleId;

        DB::table('roles')->insert([
            ['id' => $this->ids['additional_role'], 'role_name' => 'Academic Reviewer', 'school_id' => $this->ids['school'], 'active' => true, 'created_at' => now()],
            ['id' => $this->ids['other_role'], 'role_name' => 'Other School Administrator', 'school_id' => $this->ids['other_school'], 'active' => true, 'created_at' => now()],
        ]);
        User::create([
            'id' => $this->ids['user'], 'school_id' => $this->ids['school'], 'role_id' => $this->ids['primary_role'],
            'username' => 'contract-user', 'password_hash' => Hash::make('correct-password'), 'email' => 'contract@example.test',
            'first_name' => 'Contract', 'last_name' => 'User', 'active' => true, 'first_login' => false,
            'auth_generation' => 1, 'is_deleted' => false,
        ]);
        DB::table('user_roles')->insert([
            ['user_id' => $this->ids['user'], 'role_id' => $this->ids['additional_role']],
            ['user_id' => $this->ids['user'], 'role_id' => $this->ids['other_role']],
        ]);
        $this->grant('primary_role', ['view_dashboard', 'shared_permission']);
        $this->grant('additional_role', ['approve_lesson_plans', 'shared_permission']);
        $this->grant('other_role', ['manage_other_school']);
    }

    public function test_valid_login_returns_compatible_token_and_safe_authoritative_context(): void
    {
        $response = $this->login();

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonPath('data.user.school.id', $this->ids['school'])
            ->assertJsonPath('data.user.school.name', 'Contract School')
            ->assertJsonPath('data.user.roles', ['Academic Reviewer', 'Teacher'])
            ->assertJsonPath('data.user.permissions', ['approve_lesson_plans', 'shared_permission', 'view_dashboard'])
            ->assertJsonMissingPath('data.user.role_id')
            ->assertJsonMissingPath('data.user.password_hash')
            ->assertJsonMissingPath('data.user.password_reset_token')
            ->assertJsonMissingPath('data.user.manage_other_school');

        $this->assertSame($response->json('token'), $response->json('data.token'));
        $this->assertSame($response->json('user'), $response->json('data.user'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertNotContains('Other School Administrator', $response->json('data.user.roles'));
        $this->assertNotContains('manage_other_school', $response->json('data.user.permissions'));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->ids['user'], 'action' => 'authentication_login_succeeded',
            'old_values' => null, 'new_values' => null,
        ]);
    }

    public function test_me_uses_the_same_safe_resource_contract(): void
    {
        $login = $this->login();
        $me = $this->withToken($login->json('token'))->getJson('/api/auth/me');

        $me->assertOk()->assertJsonPath('data.user.id', $this->ids['user']);
        $this->assertSame($login->json('data.user'), $me->json('data.user'));
        $this->assertSame($me->json('user'), $me->json('data.user'));
    }

    public function test_refresh_returns_new_token_and_recalculates_roles_and_permissions(): void
    {
        $login = $this->login();
        $newRole = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $newRole, 'role_name' => 'Senior Teacher', 'school_id' => null, 'active' => true, 'created_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $this->ids['user'], 'role_id' => $newRole]);
        $permissionId = $this->permission('view_new_contract_permission');
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $newRole, 'permission_id' => $permissionId, 'created_at' => now()]);

        $refresh = $this->withToken($login->json('token'))->postJson('/api/auth/refresh');

        $refresh->assertOk()
            ->assertJsonPath('data.user.roles', ['Academic Reviewer', 'Senior Teacher', 'Teacher'])
            ->assertJsonPath('data.user.permissions', ['approve_lesson_plans', 'shared_permission', 'view_dashboard', 'view_new_contract_permission']);
        $this->assertNotSame($login->json('token'), $refresh->json('data.token'));
        $this->withToken($login->json('token'))->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($refresh->json('data.token'))->getJson('/api/auth/me')->assertOk();
    }

    public function test_login_failures_are_generic_and_validation_is_stable(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors(['username', 'password']);

        $wrongUser = $this->postJson('/api/auth/login', ['username' => 'does-not-exist', 'password' => 'wrong']);
        $wrongPassword = $this->postJson('/api/auth/login', ['username' => 'contract-user', 'password' => 'wrong']);
        $wrongUser->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $wrongPassword->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->assertSame($wrongUser->json(), $wrongPassword->json());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'authentication_login_failed')->count());
        $this->assertFalse(DB::table('audit_logs')->whereNotNull('old_values')->orWhereNotNull('new_values')->exists());
    }

    public function test_inactive_locked_suspended_and_deleted_users_are_denied_generically(): void
    {
        foreach ([
            ['active' => false],
            ['account_locked_until' => now()->addMinute()],
            ['suspended_at' => now()],
            ['is_deleted' => true],
        ] as $state) {
            DB::table('users')->where('id', $this->ids['user'])->update(array_merge([
                'active' => true, 'account_locked_until' => null, 'suspended_at' => null, 'is_deleted' => false,
            ], $state));
            $this->login()->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
        }
    }

    public function test_unavailable_school_is_denied_without_leaking_lifecycle_details(): void
    {
        DB::table('schools')->where('id', $this->ids['school'])->update(['active' => false, 'lifecycle_state' => 'suspended']);
        $this->login()->assertForbidden()->assertExactJson(['success' => false, 'message' => 'Access is unavailable.']);
    }

    public function test_logout_revokes_the_token_and_effective_permission_resolution_is_centralized(): void
    {
        $token = $this->login()->json('token');
        $user = User::findOrFail($this->ids['user']);
        $this->assertTrue(app(AuthContextService::class)->hasPermission($user, 'approve_lesson_plans'));
        $this->assertFalse(app(AuthContextService::class)->hasPermission($user, 'manage_other_school'));

        $this->withToken($token)->postJson('/api/auth/logout')
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Successfully logged out']);
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    private function login()
    {
        return $this->postJson('/api/auth/login', ['username' => 'contract-user', 'password' => 'correct-password']);
    }

    private function grant(string $roleKey, array $permissions): void
    {
        foreach ($permissions as $name) {
            $permissionId = $this->permission($name);
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'role_id' => $this->ids[$roleKey],
                'permission_id' => $permissionId, 'created_at' => now(),
            ]);
        }
    }

    private function permission(string $name): string
    {
        $id = DB::table('permissions')->where('permission_name', $name)->value('id');
        if ($id) {
            return $id;
        }

        $id = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $id, 'permission_name' => $name, 'created_at' => now()]);

        return $id;
    }
}
