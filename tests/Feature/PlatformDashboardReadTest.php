<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class PlatformDashboardReadTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private User $otherOwner;

    private User $admin;

    private object $school;

    private object $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('platform-read-test-secret-', 3)]);
        $this->school = SchoolBuilder::create(['lifecycle_state' => 'active']);
        $this->otherSchool = SchoolBuilder::create(['lifecycle_state' => 'active']);
        $platformRole = $this->role('Platform Owner');
        $adminRole = $this->role('School Admin');
        // Fixture-only claims deliberately include the platform permission on a
        // school identity, proving that permissions alone cannot change scope.
        foreach ([$platformRole, $adminRole] as $role) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach (['access_administrator_portal', 'access_platform_administration', 'view_admin_tasks', 'view_admin_alerts'] as $permission) {
                $id = DB::table('permissions')->where('permission_name', $permission)->value('id');
                $this->assertNotNull($id, 'Required migrated permission: '.$permission);
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $id, 'created_at' => now()]);
            }
        }
        $this->owner = $this->user((object) ['id' => null], $platformRole);
        $this->otherOwner = $this->user((object) ['id' => null], $platformRole);
        $this->admin = $this->user($this->school, $adminRole);
    }

    public function test_platform_routes_are_read_only_permission_guarded_and_do_not_remove_school_tenant_guards(): void
    {
        foreach (['tasks', 'alerts'] as $kind) {
            $platform = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/admin/platform/'.$kind);
            $school = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/admin/'.$kind);
            $this->assertNotNull($platform);
            $this->assertNotNull($school);
            $this->assertSame(['GET', 'HEAD'], $platform->methods());
            $this->assertContains('jwt', $platform->gatherMiddleware());
            $this->assertContains('permission:access_platform_administration', $platform->gatherMiddleware());
            $this->assertContains('permission:view_admin_'.$kind, $platform->gatherMiddleware());
            $this->assertNotContains('tenant', $platform->gatherMiddleware());
            $this->assertContains('tenant', $school->gatherMiddleware());
            $this->assertContains('module.permission', $school->gatherMiddleware());
        }
    }

    public function test_platform_alerts_include_only_open_global_rows_and_the_current_actors_state(): void
    {
        $global = $this->alert(null, 'Platform-only notice');
        $closed = $this->alert(null, 'Closed platform notice', 'closed');
        $school = $this->alert($this->school->id, 'School Alpha private notice');
        $foreign = $this->alert($this->otherSchool->id, 'School Beta private notice');
        foreach ([[$this->owner, 'acknowledged'], [$this->otherOwner, 'dismissed']] as [$user, $state]) {
            DB::table('administrator_alert_states')->insert(['id' => (string) Str::uuid(), 'alert_id' => $global, 'user_id' => $user->id, 'state' => $state, 'changed_at' => now()]);
        }
        $response = $this->withToken(JWTAuth::fromUser($this->owner))->getJson('/api/admin/platform/alerts')->assertOk();
        $rows = collect($response->json('data'));
        $this->assertSame('acknowledged', $rows->firstWhere('id', $global)['user_state']);
        foreach ([$closed, $school, $foreign, 'School Alpha private notice', 'School Beta private notice'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame(['id', 'type', 'severity', 'title', 'safe_message', 'created_at', 'user_state'], array_keys($rows->firstWhere('id', $global)));
        $this->assertDatabaseHas('administrator_alert_states', ['alert_id' => $global, 'user_id' => $this->otherOwner->id, 'state' => 'dismissed']);
        $this->assertDatabaseHas('administrator_alerts', ['id' => $global, 'status' => 'open']);

        $own = $this->withToken(JWTAuth::fromUser($this->admin))->getJson('/api/admin/alerts')->assertOk();
        $this->assertContains($school, array_column($own->json('data'), 'id'));
        $this->assertNotContains($foreign, array_column($own->json('data'), 'id'));
        $this->assertNotContains($global, array_column($own->json('data'), 'id'));
    }

    public function test_platform_tasks_ignore_school_providers_and_diagnostics_and_return_no_private_payloads(): void
    {
        DB::table('administrator_provider_configurations')->delete();
        DB::table('administrator_diagnostic_runs')->delete();
        $this->provider('school', $this->school->id, 'school-provider-secret');
        $this->diagnostic('school', $this->otherSchool->id);
        $first = $this->withToken(JWTAuth::fromUser($this->owner))->getJson('/api/admin/platform/tasks')->assertOk();
        $keys = array_column($first->json('data'), 'key');
        $this->assertNotContains('provider_secret_missing', $keys);
        $this->assertNotContains('provider_key_rotation_due', $keys);
        $this->assertNotContains('critical_diagnostic_failure', $keys);
        foreach ($keys as $key) {
            $this->assertFalse(str_starts_with($key, 'missing_'));
            $this->assertNotSame('pending_user_activation', $key);
        }
        $this->provider('platform', null, 'platform-provider-secret');
        $this->diagnostic('platform', null);
        $response = $this->withToken(JWTAuth::fromUser($this->owner))->getJson('/api/admin/platform/tasks')->assertOk();
        $keys = array_column($response->json('data'), 'key');
        $this->assertContains('provider_secret_missing', $keys);
        $this->assertContains('provider_key_rotation_due', $keys);
        $this->assertContains('critical_diagnostic_failure', $keys);
        foreach (['school-provider-secret', 'platform-provider-secret', 'private-diagnostic-content', $this->school->id, $this->otherSchool->id] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_anonymous_and_school_identities_cannot_read_platform_widgets(): void
    {
        foreach (['tasks', 'alerts'] as $kind) {
            $this->getJson('/api/admin/platform/'.$kind)->assertUnauthorized();
        }
        foreach (['tasks', 'alerts'] as $kind) {
            $this->withToken(JWTAuth::fromUser($this->admin))->getJson('/api/admin/platform/'.$kind)->assertForbidden();
        }
        foreach (['tasks', 'alerts'] as $kind) {
            $this->withToken(JWTAuth::fromUser($this->owner))->getJson('/api/admin/'.$kind)->assertForbidden();
        }
    }

    public function test_platform_scope_cannot_be_selected_or_spoofed_with_school_inputs(): void
    {
        foreach (['tasks', 'alerts'] as $kind) {
            $this->withToken(JWTAuth::fromUser($this->owner))->getJson('/api/admin/platform/'.$kind.'?school_id='.$this->school->id)->assertStatus(422);
            $this->withToken(JWTAuth::fromUser($this->owner))->withHeader('X-School-Id', $this->otherSchool->id)->getJson('/api/admin/platform/'.$kind)->assertStatus(422);
            $this->flushHeaders();
        }
    }

    public function test_each_platform_widget_requires_its_own_read_permission(): void
    {
        foreach (['tasks', 'alerts'] as $kind) {
            $permission = DB::table('permissions')->where('permission_name', 'view_admin_'.$kind)->value('id');
            DB::table('role_permissions')->where('role_id', $this->owner->role_id)->where('permission_id', $permission)->delete();
            $this->withToken(JWTAuth::fromUser($this->owner->fresh()))->getJson('/api/admin/platform/'.$kind)->assertForbidden();
        }
    }

    public function test_platform_alert_reads_are_bounded(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->alert(null, 'Bounded platform notice '.$i);
        }

        $this->withToken(JWTAuth::fromUser($this->owner))
            ->getJson('/api/admin/platform/alerts')->assertOk()->assertJsonCount(100, 'data');
    }

    public function test_platform_administration_permission_is_required_in_addition_to_widget_reads(): void
    {
        $permission = DB::table('permissions')->where('permission_name', 'access_platform_administration')->value('id');
        DB::table('role_permissions')->where('role_id', $this->owner->role_id)->where('permission_id', $permission)->delete();

        foreach (['tasks', 'alerts'] as $kind) {
            $this->withToken(JWTAuth::fromUser($this->owner->fresh()))
                ->getJson('/api/admin/platform/'.$kind)->assertForbidden();
        }
    }

    private function role(string $name): object
    {
        return DB::table('roles')->where('role_name', $name)->whereNull('school_id')->where('system_role', true)->where('active', true)->sole();
    }

    private function user(object $school, object $role): User
    {
        $record = UserBuilder::create($school, $role, ['email_verified_at' => now(), 'activated_at' => now(), 'auth_generation' => 1]);

        return User::findOrFail($record->id);
    }

    private function alert(?string $schoolId, string $title, string $status = 'open'): string
    {
        $id = (string) Str::uuid();
        DB::table('administrator_alerts')->insert(['id' => $id, 'school_id' => $schoolId, 'alert_key' => 'read-'.Str::uuid(), 'type' => 'system', 'severity' => 'warning', 'title' => $title, 'safe_message' => $title, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function provider(string $scope, ?string $schoolId, string $secret): void
    {
        DB::table('administrator_provider_configurations')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'scope_type' => $scope, 'category' => 'email', 'provider' => 'resend', 'configuration_encrypted' => $secret, 'secret_present' => false, 'enabled' => true, 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function diagnostic(string $scope, ?string $schoolId): void
    {
        DB::table('administrator_diagnostic_runs')->insert(['id' => (string) Str::uuid(), 'requested_by' => $this->owner->id, 'scope_type' => $scope, 'school_id' => $schoolId, 'checks' => '[]', 'safe_results' => json_encode(['detail' => 'private-diagnostic-content']), 'status' => 'critical', 'created_at' => now(), 'updated_at' => now()]);
    }
}
