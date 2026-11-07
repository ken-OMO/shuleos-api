<?php

declare(strict_types=1);

namespace Tests\Feature\TransportSubscription;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\TransportSubscription\TransportAddonService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportHttpTestCase;

class TransportAddonServiceTest extends TransportHttpTestCase
{
    public function test_permissions_exist_without_automatic_school_grants(): void
    {
        $f = $this->fixture();
        foreach (['manage_transport_subscriptions', 'view_transport_subscriptions', 'view_own_transport_subscription'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->first();
            $this->assertNotNull($permission);
            $this->assertSame('transport_subscription', $permission->module_name);
            $this->assertFalse(DB::table('role_permissions')->where('role_id', $f['actor']->role_id)
                ->where('permission_id', $permission->id)->exists());
        }
        $this->assertSame(0, DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->count());
    }

    public function test_initial_status_is_inactive_even_with_transport_management_permission(): void
    {
        $f = $this->fixture();
        $this->grant($f['actor'], 'view_own_transport_subscription');
        $before = $this->commercialSnapshot($f['school']->id);
        $result = $this->service()->schoolStatus($f['actor']);
        $this->assertFalse($result['period_active']);
        $this->assertNull($result['current_period']);
        $this->assertNull($result['next_period']);
        $this->assertSame('enforced_with_scoped_continuation', $result['operational_enforcement']);
        $this->assertSame($before, $this->commercialSnapshot($f['school']->id));
    }

    public function test_platform_issue_preserves_main_package_finance_and_permissions(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $package = (string) Str::uuid();
        DB::table('subscription_packages')->insert([
            'id' => $package, 'package_name' => 'TEST-BASE-'.Str::random(12),
            'amount' => 1000, 'duration_months' => 4, 'active' => true,
        ]);
        DB::table('school_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $f['school']->id,
            'package_id' => $package, 'start_date' => '2026-11-01',
            'expiry_date' => '2026-12-31', 'status' => 'ACTIVE',
        ]);
        $before = $this->commercialSnapshot($f['school']->id);
        $permissions = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $result = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $this->assertFalse($result['replayed']);
        $this->assertNull($result['previous_id']);
        $this->assertSame('platform_manual_attestation', $result['evidence_source']);
        $this->assertSame((string) $owner->id, $result['activated_by']);
        $this->assertSame($before, $this->commercialSnapshot($f['school']->id));
        $this->assertSame($permissions, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school']->id)
            ->where('action', 'transport_addon_activate')->count());
    }

    public function test_start_is_inclusive_and_expiry_exclusive_without_scheduler(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f, ['starts_at' => '2026-11-10T00:00:00+03:00', 'ends_at' => '2026-11-11T00:00:00+03:00']);
        $this->service()->issue($owner, $f['school']->id, $input);
        $this->assertFalse($this->service()->platformStatus($owner, $f['school']->id)['period_active']);
        CarbonImmutable::setTestNow('2026-11-09T21:00:00Z');
        $this->assertTrue($this->service()->platformStatus($owner, $f['school']->id)['period_active']);
        CarbonImmutable::setTestNow('2026-11-10T20:59:59Z');
        $this->assertTrue($this->service()->platformStatus($owner, $f['school']->id)['period_active']);
        CarbonImmutable::setTestNow('2026-11-10T21:00:00Z');
        $this->assertFalse($this->service()->platformStatus($owner, $f['school']->id)['period_active']);
    }

    public function test_adjacent_renewal_preserves_predecessor_and_has_no_gap(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $first = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $second = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, [
            'starts_at' => '2026-12-09T02:00:00+00:00', 'ends_at' => '2027-01-09T02:00:00+00:00',
        ]));
        $this->assertSame($first['period']['id'], $second['previous_id']);
        CarbonImmutable::setTestNow('2026-12-09T02:00:00Z');
        $status = $this->service()->platformStatus($owner, $f['school']->id);
        $this->assertTrue($status['period_active']);
        $this->assertSame($second['period']['id'], $status['current_period']['id']);
        $this->assertSame(2, DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->count());
    }

    public function test_exact_request_retry_is_idempotent_even_after_time_passes(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f);
        $first = $this->service()->issue($owner, $f['school']->id, $input);
        $count = DB::table('audit_logs')->where('school_id', $f['school']->id)->count();
        CarbonImmutable::setTestNow('2026-11-10T02:00:00Z');
        $again = $this->service()->issue($owner, $f['school']->id, $input);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['period'], $again['period']);
        $this->assertSame($count, DB::table('audit_logs')->where('school_id', $f['school']->id)->count());
    }

    public function test_reused_request_with_changed_evidence_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f);
        $this->service()->issue($owner, $f['school']->id, $input);
        $this->assertStatus(409, fn () => $this->service()->issue($owner, $f['school']->id, [...$input, 'reason' => 'Different evidence cannot reuse a request.']));
    }

    public function test_stale_version_and_overlapping_renewal_do_not_append(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f);
        $this->service()->issue($owner, $f['school']->id, $input);
        $this->assertStatus(409, fn () => $this->service()->issue($owner, $f['school']->id, [...$input,
            'request_id' => (string) Str::uuid(), 'evidence_reference' => 'STALE-'.Str::random(8)]));
        $this->assertStatus(409, fn () => $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f)));
        $this->assertSame(1, DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->count());
    }

    public function test_normalized_reference_cannot_be_charged_or_recorded_twice(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['evidence_reference' => ' verified-ref-123 ']));
        $this->assertStatus(409, fn () => $this->service()->issue($owner, $f['school']->id,
            $this->activation($owner, $f, ['evidence_reference' => 'VERIFIED-REF-123',
                'starts_at' => '2026-12-09T02:00:00+00:00', 'ends_at' => '2027-01-09T02:00:00+00:00'])));
    }

    public function test_school_cannot_activate_itself_or_another_school_even_with_new_permission(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $owner = $this->owner();
        foreach ([$a, $b] as $own) {
            $this->grant($own['actor'], 'manage_transport_subscriptions');
            foreach ([$a, $b] as $target) {
                $this->assertStatus(403, fn () => $this->service()->issue($own['actor'], $target['school']->id,
                    $this->activation($owner, $target)));
            }
        }
        $this->assertSame(0, DB::table('transport_addon_periods')->count());
    }

    public function test_school_status_is_own_scope_and_omits_commercial_reference_and_actor(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $owner = $this->owner();
        $this->service()->issue($owner, $a['school']->id, $this->activation($owner, $a));
        foreach ([$a, $b] as $f) {
            $this->grant($f['actor'], 'view_own_transport_subscription');
        }
        $own = $this->service()->schoolStatus($a['actor']);
        $foreign = $this->service()->schoolStatus($b['actor']);
        $this->assertTrue($own['period_active']);
        $this->assertFalse($foreign['period_active']);
        $this->assertSame((string) $b['school']->id, $foreign['school_id']);
        $this->assertStringNotContainsString('evidence_reference', json_encode($own));
        $this->assertStringNotContainsString('activated_by', json_encode($own));
    }

    public function test_school_read_permission_does_not_grant_platform_visibility(): void
    {
        $f = $this->fixture();
        $this->grant($f['actor'], 'view_own_transport_subscription');
        $this->grant($f['actor'], 'view_transport_subscriptions');
        $this->assertStatus(403, fn () => $this->service()->platformStatus($f['actor'], $f['school']->id));
    }

    public function test_revoked_platform_permission_and_stale_deactivated_actor_are_rechecked(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f);
        $this->removePermission($owner, 'manage_transport_subscriptions');
        $this->assertStatus(403, fn () => $this->service()->issue($owner, $f['school']->id, $input));
        $this->grant($owner, 'manage_transport_subscriptions');
        DB::table('users')->where('id', $owner->id)->update(['active' => false]);
        $this->assertStatus(403, fn () => $this->service()->issue($owner, $f['school']->id, $input));
    }

    public function test_backdating_excessive_duration_and_forged_fields_are_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->assertStatus(422, fn () => $this->service()->issue($owner, $f['school']->id,
            $this->activation($owner, $f, ['starts_at' => '2026-11-08T02:00:00+00:00'])));
        $this->assertStatus(422, fn () => $this->service()->issue($owner, $f['school']->id,
            $this->activation($owner, $f, ['ends_at' => '2028-11-09T02:00:00+00:00'])));
        foreach (['school_id', 'activated_by', 'amount_paid', 'package_id', 'status', 'evidence_source'] as $key) {
            $this->assertStatus(422, fn () => $this->service()->issue($owner, $f['school']->id,
                $this->activation($owner, $f, [$key => 'fabricated'])));
        }
    }

    public function test_audit_failure_rolls_back_entitlement_and_retry_can_succeed(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $input = $this->activation($owner, $f);
        $this->mock(AdministratorAuditService::class)->shouldReceive('record')->once()
            ->andThrow(new \RuntimeException('Strict add-on audit failure.'));
        try {
            $this->service()->issue($owner, $f['school']->id, $input);
            $this->fail('Expected strict audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Strict add-on audit failure.', $e->getMessage());
        }
        $this->assertSame(0, DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->count());
        $this->app->forgetInstance(TransportAddonService::class);
        $this->app->instance(AdministratorAuditService::class, new AdministratorAuditService);
        $this->assertFalse($this->service()->issue($owner, $f['school']->id, $input)['replayed']);
    }

    public function test_database_rejects_mutation_deletion_and_overlap(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $issued = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $id = $issued['period']['id'];
        $this->sqlState('23514', fn () => DB::table('transport_addon_periods')->where('id', $id)->update(['reason' => 'Rewriting evidence is forbidden.']));
        $this->sqlState('23514', fn () => DB::table('transport_addon_periods')->where('id', $id)->delete());
        $row = (array) DB::table('transport_addon_periods')->where('id', $id)->first();
        $row['id'] = (string) Str::uuid();
        $row['request_id'] = (string) Str::uuid();
        $row['previous_id'] = $id;
        $row['evidence_reference'] = 'RAW-'.Str::upper(Str::random(10));
        $this->sqlState('23P01', fn () => DB::table('transport_addon_periods')->insert($row));
    }

    public function test_populated_rollback_is_refused_without_losing_evidence(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $issued = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $migration = require database_path('migrations/2026_11_07_150000_create_transport_addon_entitlements.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retain entitlement evidence', $e->getMessage());
        }
        $this->assertTrue(DB::table('transport_addon_periods')->where('id', $issued['period']['id'])->exists());
    }

    public function test_empty_schema_can_roll_back_and_recreate_without_granting_roles(): void
    {
        $this->assertSame(0, DB::table('transport_addon_periods')->count());
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_07_150000_create_transport_addon_entitlements.php');
        $migration->down();
        $migration->up();
        $this->assertSame(0, DB::table('transport_addon_periods')->count());
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
    }

    protected function fixture(string $direction = 'to_school'): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi',
        ]);
        $role = RoleBuilder::create();
        $row = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($row->id);
        $this->grant($actor, 'manage_transport');

        return compact('school', 'actor');
    }

    private function service(): TransportAddonService
    {
        return app(TransportAddonService::class);
    }

    private function owner(): User
    {
        $role = DB::table('roles')->whereNull('school_id')->where('role_name', 'Platform Owner')
            ->where('system_role', true)->where('active', true)->value('id');
        $this->assertNotNull($role);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'school_id' => null, 'role_id' => $role,
            'username' => 'addon-owner-'.Str::lower(Str::random(12)),
            'password_hash' => Hash::make('Correct#Password99'),
            'email' => Str::lower(Str::random(16)).'@example.test',
            'first_name' => 'Addon', 'last_name' => 'Owner', 'active' => true,
            'first_login' => false, 'auth_generation' => 1, 'is_deleted' => false,
            'email_verified_at' => now(), 'activated_at' => now(), 'mfa_enabled' => true,
        ]);
        foreach (['access_administrator_portal', 'access_platform_administration',
            'manage_transport_subscriptions', 'view_transport_subscriptions'] as $permission) {
            $this->grant($owner, $permission);
        }

        return $owner;
    }

    private function activation(User $owner, array $f, array $extra = []): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'expected_version' => $this->service()->platformStatus($owner, $f['school']->id)['version'],
            'starts_at' => '2026-11-09T02:00:00+00:00', 'ends_at' => '2026-12-09T02:00:00+00:00',
            'commercial_timezone' => 'Africa/Nairobi',
            'evidence_reference' => 'TEST-ATTESTATION-'.Str::upper(Str::random(12)),
            'reason' => 'Test-only platform attestation of previously checked evidence.',
            ...$extra,
        ];
    }

    private function commercialSnapshot(string $school): array
    {
        $result = [];
        foreach (['school_subscriptions', 'school_settings', 'fee_invoices', 'payments', 'payment_allocations'] as $table) {
            $result[$table] = DB::table($table)->where('school_id', $school)->orderBy('id')->get()->toJson();
        }
        $result['subscription_packages'] = DB::table('subscription_packages')->orderBy('id')->get()->toJson();

        return $result;
    }

    private function assertStatus(int $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected rejected operation.');
        } catch (ValidationException $e) {
            $this->assertSame($expected, 422);
        } catch (AuthorizationException $e) {
            $this->assertSame($expected, 403);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($expected, $e->getStatusCode());
        }
    }

    private function sqlState(string $expected, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected database rejection.');
        } catch (QueryException $e) {
            $this->assertSame($expected, $e->errorInfo[0] ?? null);
        }
    }
}
