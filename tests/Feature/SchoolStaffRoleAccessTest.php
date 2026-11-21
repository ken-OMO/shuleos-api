<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorUserService;
use App\Services\Administrator\SchoolStaffAccessService;
use App\Services\Administrator\SchoolUserInvitationService;
use App\Services\Auth\AuthContextService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\TestCase;

class SchoolStaffRoleAccessTest extends TestCase
{
    use DatabaseTransactions;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('s', 64)]);
        Mail::fake();
        $this->school = School::create(['id' => (string) Str::uuid(), 'school_name' => 'Staff test school',
            'school_code' => 'STAFF'.Str::upper(Str::random(10)), 'active' => true, 'lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi']);
        MainSubscriptionBuilder::activeFor($this->school->id);
        $this->admin = $this->user('School Admin');
    }

    private function user(string $role, ?School $school = null): User
    {
        return User::create(['id' => (string) Str::uuid(), 'school_id' => ($school ?? $this->school)->id,
            'role_id' => Role::whereNull('school_id')->where('role_name', $role)->value('id'),
            'username' => 'staff-'.Str::random(12), 'email' => Str::random(12).'@example.test', 'first_name' => 'Test', 'last_name' => 'Staff',
            'active' => true, 'first_login' => false, 'temporary_password' => false, 'activated_at' => now(),
            'password_hash' => Hash::make('SyntheticStaffPassword123!'), 'auth_generation' => 1]);
    }

    private function grant(string $role, string $permission): void
    {
        $id = DB::table('permissions')->where('permission_name', $permission)->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'permission_name' => $permission]);
        }
        DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => Role::whereNull('school_id')->where('role_name', $role)->value('id'), 'permission_id' => $id]);
    }

    public function test_secretary_permissions_remain_narrow_even_with_legacy_or_accidental_grants(): void
    {
        foreach (['manage_finance', 'assign_school_permissions', 'view_principal_dashboard', 'manage_learners', 'view_admin_dashboard'] as $name) {
            $this->grant('School Secretary', $name);
        }
        $user = $this->user('School Secretary');
        $auth = app(AuthContextService::class);
        $this->assertEqualsCanonicalizing(['access_school_office', 'view_school_office_summary'], $auth->permissionNames($user)->all());
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))->getJson('/api/school/office')
            ->assertOk()->assertJsonPath('data.school_id', $this->school->id)->assertJsonPath('data.business_forms_available', false);
        $this->getJson('/api/admin/school/staff-access')->assertForbidden();
        $this->getJson('/api/admin/dashboard/school')->assertForbidden();
    }

    public function test_headteacher_has_academic_dashboard_but_no_finance_or_administration_without_separate_role(): void
    {
        foreach (['manage_finance', 'view_finance_oversight', 'view_school_finance_summary', 'view_admin_dashboard', 'access_administrator_portal', 'assign_school_permissions', 'unreviewed_staff_capability'] as $name) {
            $this->grant('Headteacher', $name);
        }
        $user = $this->user('Headteacher');
        $auth = app(AuthContextService::class);
        $this->assertTrue($auth->hasPermission($user, 'view_principal_dashboard'));
        foreach (['manage_finance', 'view_finance_oversight', 'view_school_finance_summary', 'view_admin_dashboard', 'access_administrator_portal', 'assign_school_permissions', 'unreviewed_staff_capability'] as $name) {
            $this->assertFalse($auth->hasPermission($user, $name), $name);
        }
        $this->assertFalse(app(LeadershipPortalAccessService::class)->scope($user)['finance']);
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))->getJson('/api/admin/school/staff-access')->assertForbidden();
    }

    public function test_admin_can_add_own_headteacher_role_then_revoke_with_audit_and_session_invalidation(): void
    {
        $this->grant('School Admin', 'manage_finance');
        $service = app(SchoolStaffAccessService::class);
        $service->change($this->admin, $this->admin->id, 'Headteacher', true);
        $context = app(AuthContextService::class)->resolve($this->admin);
        $this->assertContains('School Admin', $context['roles']);
        $this->assertContains('Headteacher', $context['roles']);
        $this->assertTrue(app(AuthContextService::class)->hasPermission($this->admin, 'access_administrator_portal'));
        $this->assertTrue(app(AuthContextService::class)->hasPermission($this->admin, 'manage_finance'));
        $this->assertSame('headteacher', app(LeadershipPortalAccessService::class)->scope($this->admin)['role_key']);
        $this->assertFalse(app(LeadershipPortalAccessService::class)->scope($this->admin)['finance']);
        // Repeated assignment is idempotent and does not send mail.
        $service->change($this->admin, $this->admin->id, 'Headteacher', true);
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $this->admin->id)->where('action', 'staff_role_assigned')->count());
        $service->change($this->admin, $this->admin->id, 'Headteacher', false);
        $this->assertNotContains('Headteacher', app(AuthContextService::class)->resolve($this->admin)['roles']);
        $this->assertSame(2, (int) $this->admin->fresh()->auth_generation);
        $this->assertDatabaseHas('audit_logs', ['record_id' => $this->admin->id, 'action' => 'staff_role_revoked']);
        Mail::assertNothingSent();
    }

    public function test_foreign_school_user_and_unsupported_privileged_role_cannot_be_assigned(): void
    {
        $foreign = School::create(['id' => (string) Str::uuid(), 'school_name' => 'Other school', 'school_code' => 'OTHER'.Str::random(10), 'active' => true]);
        $target = $this->user('Headteacher', $foreign);
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->admin))
            ->putJson('/api/admin/school/staff-access/'.$target->id, ['role' => 'Headteacher', 'assigned' => true])->assertNotFound();
        $this->putJson('/api/admin/school/staff-access/'.$this->admin->id, ['role' => 'Platform Owner', 'assigned' => true])->assertUnprocessable();
        $this->putJson('/api/admin/school/staff-access/'.$this->admin->id, ['role' => 'Headteacher', 'assigned' => true, 'school_id' => $foreign->id])->assertUnprocessable();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $target->id)->count());
    }

    public function test_headteacher_cannot_self_assign_privileges_and_subscriptionless_changes_are_denied(): void
    {
        $headteacher = $this->user('Headteacher');
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($headteacher))
            ->putJson('/api/admin/school/staff-access/'.$headteacher->id, ['role' => 'School Secretary', 'assigned' => true])->assertForbidden();
        DB::table('school_subscriptions')->where('school_id', $this->school->id)->update(['status' => 'LOCKED']);
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->admin))
            ->putJson('/api/admin/school/staff-access/'.$this->admin->id, ['role' => 'Headteacher', 'assigned' => true])->assertForbidden();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $this->admin->id)->count());
    }

    public function test_headteacher_does_not_count_as_a_replacement_for_the_last_administrator(): void
    {
        $this->user('Headteacher');
        $support = $this->user('Support Administrator');
        $this->grant('Support Administrator', 'suspend_school_users');
        try {
            app(AdministratorUserService::class)->action($support, $this->admin->id, 'suspend');
            $this->fail('The last administrator must remain active.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame('The last active school administrator cannot be suspended.', $error->getMessage());
        }
        $this->assertTrue($this->admin->fresh()->active);
    }

    public function test_director_migration_copies_only_reviewed_administration_grants(): void
    {
        // The regression database need not contain these optional global presets.
        // Create test-owned fixtures rather than relying on historical seeder order.
        foreach (['Director', 'School Owner'] as $name) {
            if (! Role::whereNull('school_id')->where('role_name', $name)->exists()) {
                Role::create(['id' => (string) Str::uuid(), 'role_name' => $name,
                    'school_id' => null, 'system_role' => true, 'active' => true]);
            }
        }
        $this->grant('School Admin', 'staff_migration_forbidden');
        $this->grant('School Admin', 'onboard_schools');
        $migration = require database_path('migrations/2026_11_21_180000_add_school_office_role.php');
        $migration->up();
        foreach (['Director', 'School Owner'] as $name) {
            $role = Role::whereNull('school_id')->where('role_name', $name)->firstOrFail();
            $grants = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_id', $role->id)->pluck('permission_name')->all();
            $this->assertContains('access_administrator_portal', $grants);
            $this->assertNotContains('staff_migration_forbidden', $grants);
            $this->assertNotContains('onboard_schools', $grants);
        }
    }

    public function test_secretary_can_be_invited_without_granting_office_permissions_to_administrator(): void
    {
        $role = Role::whereNull('school_id')->where('role_name', 'School Secretary')->firstOrFail();
        $result = app(SchoolUserInvitationService::class)->invite($this->admin, ['first_name' => 'Test', 'last_name' => 'Secretary',
            'email' => Str::random(12).'@example.test', 'role_id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $result['id'], 'school_id' => $this->school->id, 'role_id' => $role->id, 'temporary_password' => true]);
        $this->assertFalse(app(AuthContextService::class)->hasPermission($this->admin, 'access_school_office'));
    }
}
