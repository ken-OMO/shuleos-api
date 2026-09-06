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

class PlatformStorageOperationsSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-storage-security-test-secret-',
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

    public function test_platform_storage_mutation_routes_have_platform_boundary(): void
    {
        $expected = [
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/storage/quarantine/{file}/release',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/storage/quarantine/{file}/reject',
            ],
            [
                'method' => 'POST',
                'uri' => 'api/admin/operations/storage/orphans/{file}/archive',
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
                'permission:manage_quarantined_files',
                $middleware
            );

            $this->assertNotContains(
                'tenant',
                $middleware,
                "Route [{$expectedRoute['uri']}] must not use TenantMiddleware."
            );
        }
    }

    public function test_school_admin_cannot_use_platform_storage_mutations(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'clean'
        );

        $token = JWTAuth::fromUser(
            $this->schoolAdmin($schoolId)
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_use_platform_storage_mutations(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'clean'
        );

        $this
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertUnauthorized();
    }

    public function test_platform_owner_can_release_clean_quarantined_record(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'clean'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $recordId
            )
            ->assertJsonPath(
                'data.status',
                'released'
            )
            ->assertJsonPath(
                'data.physical_delete',
                false
            );

        $this->assertDatabaseHas(
            'administrator_storage_records',
            [
                'id' => $recordId,
                'status' => 'released',
            ]
        );
    }

    public function test_release_requires_clean_scanner_state(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'infected'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertStatus(409);

        $this->assertDatabaseHas(
            'administrator_storage_records',
            [
                'id' => $recordId,
                'status' => 'quarantined',
            ]
        );
    }

    public function test_release_requires_quarantined_status(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'orphaned',
            'clean'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertStatus(409);
    }

    public function test_reject_requires_quarantined_status_and_reason(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'infected'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/reject',
                []
            )
            ->assertStatus(422);

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/reject',
                [
                    'reason' => 'Scanner confirmed unsafe content.',
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'rejected'
            );
    }

    public function test_archive_requires_orphaned_status_and_reason(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'orphaned',
            'clean'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/orphans/'
                .$recordId
                .'/archive',
                []
            )
            ->assertStatus(422);

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/orphans/'
                .$recordId
                .'/archive',
                [
                    'reason' => 'No active database reference remains.',
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'archived'
            );
    }

    public function test_archive_rejects_non_orphaned_record(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'clean'
        );

        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/orphans/'
                .$recordId
                .'/archive',
                [
                    'reason' => 'Invalid transition test.',
                ]
            )
            ->assertStatus(409);
    }

    public function test_nonexistent_storage_record_is_not_mutated(): void
    {
        $token = JWTAuth::fromUser(
            $this->platformOwner()
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .Str::uuid()
                .'/release'
            )
            ->assertNotFound();
    }

    public function test_storage_mutation_records_audit_event(): void
    {
        $schoolId = $this->school();

        $recordId = $this->storageRecord(
            $schoolId,
            'quarantined',
            'clean'
        );

        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/operations/storage/quarantine/'
                .$recordId
                .'/release'
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'user_id' => $user->id,
                'module' => 'Administrator Portal',
                'action' => 'administrator_storage_released',
                'table_name' => 'administrator_storage_records',
                'record_id' => $recordId,
            ]
        );
    }

    private function platformOwner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $this->platformRoleId,
            'username' => 'platform-storage-owner-'
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

    private function schoolAdmin(
        string $schoolId
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'role_id' => $this->schoolAdminRoleId,
            'username' => 'storage-school-admin-'
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
            'school_name' => 'Storage Security Test School',
            'school_code' => 'STOR-'
                .Str::upper(Str::random(6)),
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $schoolId;
    }

    private function storageRecord(
        string $schoolId,
        string $status,
        string $scannerState
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'administrator_storage_records'
        )->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'record_type' => 'security_test',
            'record_id' => null,
            'status' => $status,
            'scanner_state' => $scannerState,
            'safe_label' => 'security-test.txt',
            'size' => 128,
            'storage_reference_hash' => hash(
                'sha256',
                'storage-security-'.$id
            ),
            'reviewed_by' => null,
            'review_reason' => null,
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
