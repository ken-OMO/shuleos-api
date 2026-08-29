<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Auth\AuthContextService;
use App\Services\Communication\CommunicationPolicyService;
use App\Services\Communication\CommunicationRecipientResolverService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthorizationResolutionConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    private string $schoolId;

    private string $userId;

    private string $primaryRoleId;

    private string $secondaryRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->schoolId = (string) Str::uuid();
        $this->userId = (string) Str::uuid();
        $this->secondaryRoleId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $this->schoolId,
            'school_name' => 'Authorization Consistency School',
            'school_code' => 'ACS-'.strtoupper(Str::random(8)),
            'active' => true,
            'is_deleted' => false,
        ]);

        $this->primaryRoleId = (string) DB::table('roles')
            ->where('role_name', 'Teacher')
            ->value('id');

        if (! $this->primaryRoleId) {
            throw new \RuntimeException(
                'Required migrated Teacher role was not found.'
            );
        }

        DB::table('roles')->insert([
            'id' => $this->secondaryRoleId,
            'role_name' => 'Authorization Consistency Reviewer',
            'school_id' => $this->schoolId,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'school_id' => $this->schoolId,
            'role_id' => $this->primaryRoleId,
            'username' => 'authorization-consistency-'.Str::lower(Str::random(8)),
            'password_hash' => 'not-used',
            'first_name' => 'Authorization',
            'last_name' => 'Consistency',
            'active' => true,
            'first_login' => false,
            'auth_generation' => 1,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $this->userId,
            'role_id' => $this->secondaryRoleId,
        ]);
    }

    public function test_secondary_role_permission_is_consistent_across_public_authorization_services(): void
    {
        $this->grantSecondary('send_emergency_broadcasts');

        $user = $this->user();

        $authContext = app(AuthContextService::class);
        $administrator = app(AdministratorPortalAccessService::class);
        $resolver = app(CommunicationRecipientResolverService::class);
        $leadership = app(LeadershipPortalAccessService::class);

        $this->assertFalse(
            $this->primaryRoleHas('send_emergency_broadcasts'),
            'Test invariant failed: primary role unexpectedly grants the permission.'
        );

        $this->assertTrue(
            $authContext->hasPermission(
                $user,
                'send_emergency_broadcasts'
            )
        );

        $this->assertContains(
            'send_emergency_broadcasts',
            $authContext->permissionNames($user)->all()
        );

        $this->assertTrue(
            $administrator->has(
                $user,
                'send_emergency_broadcasts'
            )
        );

        $this->assertTrue(
            $resolver->hasPermission(
                $user,
                'send_emergency_broadcasts'
            )
        );

        $this->assertTrue(
            $leadership->has(
                $user,
                'send_emergency_broadcasts'
            )
        );
    }

    public function test_sms_policy_accepts_permission_granted_only_by_secondary_role(): void
    {
        $this->grantSecondary('send_emergency_broadcasts');

        $user = $this->user();

        $this->assertFalse(
            $this->primaryRoleHas('send_emergency_broadcasts')
        );

        app(CommunicationPolicyService::class)
            ->assertSmsPermission($user, 'emergency');

        $this->addToAssertionCount(1);
    }

    public function test_sms_policy_denies_when_no_effective_role_grants_permission(): void
    {
        $user = $this->user();

        $this->assertFalse(
            app(AuthContextService::class)->hasPermission(
                $user,
                'send_emergency_broadcasts'
            )
        );

        try {
            app(CommunicationPolicyService::class)
                ->assertSmsPermission($user, 'emergency');

            $this->fail(
                'SMS policy should deny when no effective role grants the permission.'
            );
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_public_authorization_services_consistently_deny_missing_permission(): void
    {
        $user = $this->user();

        $permission = 'authorization_consistency_missing_permission';

        $this->assertFalse(
            app(AuthContextService::class)
                ->hasPermission($user, $permission)
        );

        $this->assertFalse(
            app(AdministratorPortalAccessService::class)
                ->has($user, $permission)
        );

        $this->assertFalse(
            app(CommunicationRecipientResolverService::class)
                ->hasPermission($user, $permission)
        );

        $this->assertFalse(
            app(LeadershipPortalAccessService::class)
                ->has($user, $permission)
        );
    }

    private function user(): User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->userId);
    }

    private function grantSecondary(string $permissionName): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)
            ->value('id');

        if (! $permissionId) {
            $permissionId = (string) Str::uuid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'permission_name' => $permissionName,
                'created_at' => now(),
            ]);
        }

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $this->secondaryRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    private function primaryRoleHas(string $permissionName): bool
    {
        return DB::table('role_permissions')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id'
            )
            ->where(
                'role_permissions.role_id',
                $this->primaryRoleId
            )
            ->where(
                'permissions.permission_name',
                $permissionName
            )
            ->exists();
    }
}
