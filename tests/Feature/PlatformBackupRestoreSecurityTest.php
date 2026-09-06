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

class PlatformBackupRestoreSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-backup-restore-security-secret-',
                3
            ),
            'administrator_operations.restore_execution_enabled' => false,
        ]);

        $this->platformRoleId = $this->systemRole(
            'Platform Owner'
        );

        $this->schoolAdminRoleId = $this->systemRole(
            'School Admin'
        );
    }

    public function test_restore_mutation_routes_have_platform_boundary(): void
    {
        $expected = [
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/restores/preview',
                'permission' => 'permission:create_restore_requests',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/restores',
                'permission' => 'permission:create_restore_requests',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/restores/{restore}/cancel',
                'permission' => 'permission:create_restore_requests',
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
                "Restore route [{$expectedRoute['uri']}] must require platform administration."
            );

            $this->assertContains(
                $expectedRoute['permission'],
                $middleware
            );

            $this->assertNotContains(
                'tenant',
                $middleware,
                "Restore route [{$expectedRoute['uri']}] must not use TenantMiddleware."
            );
        }
    }

    public function test_school_admin_cannot_access_platform_restore_mutations(): void
    {
        $schoolId = $this->school();

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $backupId = $this->backup(
            null,
            'platform',
            'verified'
        );

        $restoreId = $this->restore(
            $backupId,
            $this->backup(
                null,
                'platform',
                'verified'
            ),
            'validation_requested'
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $backupId,
                    'dry_run' => true,
                ]
            )
            ->assertForbidden();

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => (string) Str::uuid(),
                    'backup_id' => $backupId,
                    'pre_restore_backup_id' => (string) Str::uuid(),
                    'dry_run' => true,
                    'reason' => 'Security boundary test.',
                    'confirmation' => 'REQUEST PLATFORM RESTORE',
                ]
            )
            ->assertForbidden();

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/'
                .$restoreId
                .'/cancel'
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_restore_mutations(): void
    {
        $backupId = (string) Str::uuid();

        $this
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $backupId,
                    'dry_run' => true,
                ]
            )
            ->assertUnauthorized();

        $this
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => (string) Str::uuid(),
                    'backup_id' => $backupId,
                    'pre_restore_backup_id' => (string) Str::uuid(),
                    'dry_run' => true,
                    'reason' => 'Unauthenticated test.',
                    'confirmation' => 'REQUEST PLATFORM RESTORE',
                ]
            )
            ->assertUnauthorized();
    }

    public function test_platform_owner_can_preview_verified_platform_backup_for_restore(): void
    {
        $backupId = $this->backup(
            null,
            'platform',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $backupId,
                    'dry_run' => true,
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_restore_preview_rejects_non_verified_platform_backup(): void
    {
        $backupId = $this->backup(
            null,
            'platform',
            'completed'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $backupId,
                    'dry_run' => true,
                ]
            )
            ->assertStatus(422);
    }

    public function test_restore_preview_rejects_school_scoped_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            $schoolId,
            'school',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $backupId,
                    'dry_run' => true,
                ]
            )
            ->assertStatus(422);
    }

    public function test_restore_creation_requires_exact_confirmation_phrase(): void
    {
        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $user = $this->platformOwner(
            now()
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $preview = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $target,
                    'dry_run' => true,
                ]
            );

        if ($preview->status() !== 200) {
            $this->fail(
                'Restore preview must be reachable before confirmation can be tested.'
            );
        }

        $previewId = $preview->json(
            'data.preview_id'
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => $previewId,
                    'backup_id' => $target,
                    'pre_restore_backup_id' => $preRestore,
                    'dry_run' => true,
                    'reason' => 'Confirmation phrase security test.',
                    'confirmation' => 'WRONG PHRASE',
                ]
            )
            ->assertForbidden();
    }

    public function test_restore_creation_requires_recent_authentication(): void
    {
        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $user = $this->platformOwner(
            now()->subHours(2)
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $preview = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $target,
                    'dry_run' => true,
                ]
            );

        if ($preview->status() !== 200) {
            $this->fail(
                'Restore preview must be reachable before recent authentication can be tested.'
            );
        }

        $previewId = $preview->json(
            'data.preview_id'
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => $previewId,
                    'backup_id' => $target,
                    'pre_restore_backup_id' => $preRestore,
                    'dry_run' => true,
                    'reason' => 'Recent authentication security test.',
                    'confirmation' => 'REQUEST PLATFORM RESTORE',
                ]
            )
            ->assertForbidden();
    }

    public function test_restore_creation_requires_matching_preview(): void
    {
        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $user = $this->platformOwner(
            now()
        );

        $this->activePlatformMaintenance(
            $user
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => (string) Str::uuid(),
                    'backup_id' => $target,
                    'pre_restore_backup_id' => $preRestore,
                    'dry_run' => true,
                    'reason' => 'Invalid preview test.',
                    'confirmation' => 'REQUEST PLATFORM RESTORE',
                ]
            )
            ->assertForbidden();
    }

    public function test_non_dry_run_restore_requires_execution_permission_and_remains_disabled_without_tooling(): void
    {
        config([
            'administrator_operations.restore_execution_enabled' => false,
        ]);

        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $user = $this->platformOwner(
            now()
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $preview = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/preview',
                [
                    'backup_id' => $target,
                    'dry_run' => false,
                ]
            );

        if ($preview->status() !== 200) {
            $this->fail(
                'Restore preview must be reachable before execution safety can be tested.'
            );
        }

        $previewId = $preview->json(
            'data.preview_id'
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores',
                [
                    'preview_id' => $previewId,
                    'backup_id' => $target,
                    'pre_restore_backup_id' => $preRestore,
                    'dry_run' => false,
                    'reason' => 'Execution-disabled safety test.',
                    'confirmation' => 'REQUEST PLATFORM RESTORE',
                ]
            );

        $this->assertNotSame(
            200,
            $response->status(),
            'Real restore execution must not succeed while trusted tooling is disabled.'
        );
    }

    public function test_restore_cancel_only_allows_pending_states(): void
    {
        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $restoreId = $this->restore(
            $target,
            $preRestore,
            'completed'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/'
                .$restoreId
                .'/cancel'
            )
            ->assertStatus(409);
    }

    public function test_restore_cancel_is_audited(): void
    {
        $target = $this->backup(
            null,
            'platform',
            'verified'
        );

        $preRestore = $this->backup(
            null,
            'platform',
            'verified'
        );

        $restoreId = $this->restore(
            $target,
            $preRestore,
            'validation_requested'
        );

        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/restores/'
                .$restoreId
                .'/cancel'
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'user_id' => $user->id,
                'module' => 'Administrator Portal',
                'action' => 'administrator_restore_cancelled',
                'table_name' => 'administrator_restores',
                'record_id' => $restoreId,
            ]
        );
    }

    public function test_school_admin_cannot_verify_platform_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            null,
            'platform',
            'completed',
            true
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/verify'
            )
            ->assertNotFound();
    }

    public function test_school_admin_cannot_archive_platform_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            null,
            'platform',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/archive'
            )
            ->assertNotFound();
    }

    public function test_school_admin_can_verify_own_school_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            $schoolId,
            'school',
            'completed',
            true
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/verify'
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $schoolId,
                'scope_type' => 'school',
                'status' => 'verified',
            ]
        );
    }

    public function test_school_admin_cannot_verify_other_school_backup(): void
    {
        $ownerSchoolId = $this->school();
        $otherSchoolId = $this->school();

        $backupId = $this->backup(
            $ownerSchoolId,
            'school',
            'completed',
            true
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($otherSchoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/verify'
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $ownerSchoolId,
                'scope_type' => 'school',
                'status' => 'completed',
            ]
        );
    }

    public function test_school_admin_can_archive_own_school_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            $schoolId,
            'school',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/archive'
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $schoolId,
                'scope_type' => 'school',
                'status' => 'archived',
            ]
        );
    }

    public function test_school_admin_cannot_archive_other_school_backup(): void
    {
        $ownerSchoolId = $this->school();
        $otherSchoolId = $this->school();

        $backupId = $this->backup(
            $ownerSchoolId,
            'school',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($otherSchoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/archive'
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $ownerSchoolId,
                'scope_type' => 'school',
                'status' => 'verified',
            ]
        );
    }

    public function test_platform_owner_cannot_verify_school_scoped_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            $schoolId,
            'school',
            'completed',
            true
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/verify'
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $schoolId,
                'scope_type' => 'school',
                'status' => 'completed',
            ]
        );
    }

    public function test_platform_owner_cannot_archive_school_scoped_backup(): void
    {
        $schoolId = $this->school();

        $backupId = $this->backup(
            $schoolId,
            'school',
            'verified'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/archive'
            )
            ->assertNotFound();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'school_id' => $schoolId,
                'scope_type' => 'school',
                'status' => 'verified',
            ]
        );
    }

    public function test_platform_owner_can_verify_platform_backup_without_school_scope(): void
    {
        $backupId = $this->backup(
            null,
            'platform',
            'completed',
            true
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/backups/'
                .$backupId
                .'/verify'
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'administrator_backups',
            [
                'id' => $backupId,
                'scope_type' => 'platform',
                'school_id' => null,
                'status' => 'verified',
            ]
        );
    }

    private function platformOwner(
        $lastLogin = null
    ): User {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $this->platformRoleId,
            'username' => 'recovery-platform-owner-'
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

        if ($lastLogin !== null) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'last_login' => $lastLogin,
                ]);

            $user->refresh();
        }

        return $user;
    }

    private function schoolAdmin(
        string $schoolId
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'role_id' => $this->schoolAdminRoleId,
            'username' => 'recovery-school-admin-'
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
            'school_name' => 'Recovery Security Test School',
            'school_code' => 'REC-'
                .Str::upper(Str::random(6)),
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $schoolId;
    }

    private function activePlatformMaintenance(
        User $user
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'administrator_maintenance_windows'
        )->insert([
            'id' => $id,
            'school_id' => null,
            'scope_type' => 'platform',
            'status' => 'active',
            'reason' => 'Restore security test maintenance.',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'created_by' => $user->id,
            'activated_by' => $user->id,
            'completed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function backup(
        ?string $schoolId,
        string $scopeType,
        string $status,
        bool $withChecksum = false
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'administrator_backups'
        )->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'scope_type' => $scopeType,
            'backup_type' => 'database_metadata',
            'status' => $status,
            'tooling_available' => true,
            'checksum' => $withChecksum
                ? hash('sha256', 'backup-'.$id)
                : (
                    $status === 'verified'
                        ? hash('sha256', 'backup-'.$id)
                        : null
                ),
            'size' => 128,
            'safe_manifest' => json_encode([
                'backup_type' => 'database_metadata',
                'scope_type' => $scopeType,
                'contains_secrets' => false,
                'restorable' => false,
            ]),
            'failure_code' => null,
            'retention_until' => now()->addDays(30),
            'verified_at' => $status === 'verified'
                ? now()
                : null,
            'requested_by' => $this->platformOwner()->id,
            'verified_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function restore(
        string $backupId,
        string $preRestoreBackupId,
        string $status
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'administrator_restores'
        )->insert([
            'id' => $id,
            'backup_id' => $backupId,
            'pre_restore_backup_id' => $preRestoreBackupId,
            'status' => $status,
            'reason' => 'Security test restore.',
            'dry_run' => true,
            'execution_enabled' => false,
            'requested_by' => $this->platformOwner()->id,
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
