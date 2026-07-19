<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administrator\Operations\AdministratorFeatureFlagService;
use App\Services\Administrator\Operations\AdministratorIntegrationService;
use App\Services\Administrator\Operations\AdministratorOperationsAccessService;
use App\Services\Administrator\Operations\AdministratorProviderConfigurationService;
use App\Services\Administrator\Operations\AdministratorRecoveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdministratorPortalPhaseTwoTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        DB::beginTransaction();
        foreach (['school', 'other_school', 'admin_role', 'platform_role', 'support_role', 'admin', 'platform', 'support'] as $key) {
            $this->ids[$key] = (string) Str::uuid();
        }
        DB::table('schools')->insert([['id' => $this->ids['school'], 'school_name' => 'Operations School', 'school_code' => 'OPS-'.Str::random(6), 'active' => true, 'is_deleted' => false, 'lifecycle_state' => 'active'], ['id' => $this->ids['other_school'], 'school_name' => 'Other Operations School', 'school_code' => 'OTHER-'.Str::random(6), 'active' => true, 'is_deleted' => false, 'lifecycle_state' => 'active']]);
        DB::table('roles')->insert([['id' => $this->ids['admin_role'], 'role_name' => 'School Admin', 'active' => true], ['id' => $this->ids['platform_role'], 'role_name' => 'Platform Owner', 'active' => true], ['id' => $this->ids['support_role'], 'role_name' => 'Support Administrator', 'active' => true]]);
        $this->user('admin', 'admin_role');
        $this->user('platform', 'platform_role');
        $this->user('support', 'support_role');
        $schoolPermissions = ['access_administrator_portal', 'access_administrator_operations', 'manage_school_feature_flags', 'manage_school_maintenance', 'view_provider_configuration', 'view_cache_operations', 'clear_safe_cache_groups', 'view_storage_operations', 'manage_quarantined_files', 'view_backup_operations', 'create_backups', 'verify_backups', 'archive_backups', 'manage_api_keys', 'manage_webhooks', 'view_operational_diagnostics', 'run_operational_diagnostics', 'view_release_metadata'];
        $platformPermissions = collect($schoolPermissions)->merge(['access_platform_administration', 'manage_platform_feature_flags', 'manage_platform_maintenance', 'manage_provider_configuration', 'rotate_provider_secrets', 'view_queue_operations', 'retry_failed_jobs', 'forget_failed_jobs', 'view_scheduler_operations', 'run_allowlisted_scheduler_tasks', 'view_application_logs', 'view_restore_operations', 'create_restore_requests', 'execute_restore_operations', 'manage_system_notices', 'manage_platform_settings', 'view_disaster_recovery_readiness'])->all();
        $this->grant('admin_role', $schoolPermissions);
        $this->grant('platform_role', $platformPermissions);
        $this->grant('support_role', ['access_administrator_portal']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_school_platform_support_and_restore_authority_are_strictly_separated(): void
    {
        $access = app(AdministratorOperationsAccessService::class);
        $this->assertSame('school', $access->school($this->model('admin'), 'manage_school_feature_flags')['operation_scope']);
        $this->assertSame('platform', $access->platform($this->model('platform'), 'manage_platform_feature_flags')['operation_scope']);
        try {
            $access->platform($this->model('admin'), 'manage_platform_feature_flags');
            $this->fail('School admin obtained platform operations.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->expectException(AuthorizationException::class);
        $access->school($this->model('support'), 'view_provider_configuration');
    }

    public function test_feature_flags_are_allowlisted_scoped_expiring_and_append_only(): void
    {
        $service = app(AdministratorFeatureFlagService::class);
        $flag = $service->save($this->model('admin'), ['key' => 'parent_mobile_api', 'scope_type' => 'school', 'enabled' => true, 'ends_at' => now()->subMinute()->toDateTimeString()]);
        $this->assertFalse($service->resolve('parent_mobile_api', $this->ids['school']));
        $service->action($this->model('admin'), $flag['id'], 'disable');
        $this->assertSame(2, DB::table('administrator_feature_flag_history')->where('flag_id', $flag['id'])->count());
        $this->expectException(ValidationException::class);
        $service->save($this->model('admin'), ['key' => 'administrator_portal_v2', 'scope_type' => 'school', 'enabled' => true]);
    }

    public function test_percentage_rollout_is_deterministic_and_requires_a_subject(): void
    {
        $service = app(AdministratorFeatureFlagService::class);
        $service->save($this->model('admin'), ['key' => 'parent_mobile_api', 'scope_type' => 'school', 'enabled' => true, 'rollout_percentage' => 50]);

        $this->assertFalse($service->resolve('parent_mobile_api', $this->ids['school']));
        $first = $service->resolve('parent_mobile_api', $this->ids['school'], $this->ids['admin']);
        $this->assertSame($first, $service->resolve('parent_mobile_api', $this->ids['school'], $this->ids['admin']));
    }

    public function test_provider_secrets_are_encrypted_write_only_and_rotation_is_historical(): void
    {
        $service = app(AdministratorProviderConfigurationService::class);
        $safe = $service->save($this->model('platform'), 'email', ['provider' => 'resend', 'secrets' => ['api_key' => 'secret-value-123'], 'enabled' => true]);
        $row = DB::table('administrator_provider_configurations')->where('id', $safe['id'])->first();
        $this->assertStringNotContainsString('secret-value-123', $row->configuration_encrypted);
        $this->assertStringContainsString('secret-value-123', Crypt::decryptString($row->configuration_encrypted));
        $this->assertArrayNotHasKey('configuration_encrypted', $safe);
        $service->save($this->model('platform'), 'email', ['provider' => 'resend', 'secrets' => ['api_key' => 'rotated-value-456']], true);
        $this->assertSame(2, DB::table('administrator_provider_configuration_history')->count());
    }

    public function test_api_key_plaintext_is_shown_once_and_only_hash_is_persisted(): void
    {
        $service = app(AdministratorIntegrationService::class);
        $created = $service->createApiKey($this->model('admin'), ['scope_type' => 'school', 'name' => 'Mobile integration', 'scopes' => ['school.read'], 'expires_at' => now()->addMonth()]);
        $row = DB::table('administrator_api_keys')->where('id', $created['id'])->first();
        $this->assertSame(hash('sha256', $created['plaintext_key']), $row->key_hash);
        $this->assertStringNotContainsString($created['plaintext_key'], json_encode($row));
        $this->assertArrayNotHasKey('plaintext_key', $service->apiKeys($this->model('admin'), $created['id']));
    }

    public function test_api_key_expiry_is_bounded(): void
    {
        $this->expectException(ValidationException::class);
        app(AdministratorIntegrationService::class)->createApiKey($this->model('admin'), ['scope_type' => 'school', 'name' => 'Overlong key', 'scopes' => ['school.read'], 'expires_at' => now()->addYears(3)]);
    }

    public function test_private_webhook_target_is_rejected_without_network_access(): void
    {
        $this->expectException(ValidationException::class);
        app(AdministratorIntegrationService::class)->saveWebhook($this->model('admin'), ['scope_type' => 'school', 'name' => 'Private hook', 'endpoint' => 'https://10.0.0.1/callback', 'events' => ['learner.updated']]);
    }

    public function test_manifest_backup_is_queued_and_logical_backup_fails_closed_without_tooling(): void
    {
        $service = app(AdministratorRecoveryService::class);
        $data = ['scope_type' => 'school', 'backup_type' => 'database_logical'];
        $preview = $service->backupPreview($this->model('admin'), $data);
        $backup = $service->createBackup($this->model('admin'), $data + ['preview_id' => $preview['preview_id']]);
        $this->assertSame('queued', $backup['status']);
        $service->dispatch();
        $this->assertDatabaseHas('administrator_backups', ['id' => $backup['id'], 'status' => 'failed', 'failure_code' => 'trusted_tooling_unavailable']);
    }

    public function test_all_operational_routes_are_permissioned_and_no_destructive_bulk_endpoints_exist(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/operations'));
        $this->assertGreaterThanOrEqual(78, $routes->count());
        foreach ($routes as $route) {
            $this->assertNotNull(collect($route->gatherMiddleware())->first(fn ($middleware) => str_starts_with($middleware, 'permission:')), $route->uri());
        }
        foreach (['flush', 'truncate', 'shell', 'artisan', 'delete-log', 'restore/execute'] as $forbidden) {
            $this->assertFalse($routes->contains(fn ($route) => str_contains($route->uri(), $forbidden)));
        }
    }

    private function user(string $key, string $role): void
    {
        User::create(['id' => $this->ids[$key], 'school_id' => $this->ids['school'], 'role_id' => $this->ids[$role], 'username' => $key.'-'.Str::random(6), 'password_hash' => bcrypt('password'), 'first_name' => Str::headline($key), 'last_name' => 'Operator', 'active' => true, 'first_login' => false, 'is_deleted' => false, 'auth_generation' => 1, 'last_login' => now()]);
    }

    private function model(string $key): User
    {
        return User::with('role')->findOrFail($this->ids[$key]);
    }

    private function grant(string $role, array $permissions): void
    {
        foreach ($permissions as $name) {
            $id = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $id, 'permission_name' => $name, 'module_name' => 'administrator_operations', 'created_at' => now()]);
            } DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $this->ids[$role], 'permission_id' => $id, 'created_at' => now()]);
        }
    }

    private function schema(): void
    {
        if (! Schema::hasTable('schools')) {
            Schema::create('schools', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('school_name');
                $t->string('school_code');
                $t->boolean('active')->default(true);
                $t->boolean('is_deleted')->default(false);
                $t->string('lifecycle_state')->default('active');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('role_name');
                $t->uuid('school_id')->nullable();
                $t->boolean('system_role')->default(true);
                $t->boolean('active')->default(true);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('school_id');
                $t->uuid('role_id');
                $t->string('username');
                $t->string('password_hash');
                $t->string('email')->nullable();
                $t->string('phone')->nullable();
                $t->string('first_name');
                $t->string('middle_name')->nullable();
                $t->string('last_name');
                $t->boolean('active')->default(true);
                $t->boolean('first_login')->default(false);
                $t->boolean('is_deleted')->default(false);
                $t->unsignedInteger('auth_generation')->default(1);
                $t->timestamp('last_login')->nullable();
                $t->timestamp('account_locked_until')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('permission_name')->unique();
                $t->string('module_name')->nullable();
                $t->text('description')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('role_id');
                $t->uuid('permission_id');
                $t->timestamp('created_at')->nullable();
                $t->unique(['role_id', 'permission_id']);
            });
        }
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('school_id')->nullable();
                $t->uuid('user_id');
                $t->string('module');
                $t->string('action');
                $t->string('table_name');
                $t->uuid('record_id')->nullable();
                $t->text('description')->nullable();
                $t->json('old_values')->nullable();
                $t->json('new_values')->nullable();
                $t->string('ip_address')->nullable();
                $t->text('user_agent')->nullable();
                $t->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $t) {
                $t->id();
                $t->string('uuid')->unique();
                $t->text('connection');
                $t->text('queue');
                $t->longText('payload');
                $t->longText('exception');
                $t->timestamp('failed_at');
            });
        }
        if (! Schema::hasTable('administrator_operation_previews')) {
            (require database_path('migrations/2026_07_19_030001_create_administrator_operations_phase_two_tables.php'))->up();
        }
    }
}
