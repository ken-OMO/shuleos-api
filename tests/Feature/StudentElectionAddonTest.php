<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\StudentElectionSubscription\StudentElectionAddonService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use App\Services\StudentElectionSubscription\StudentElectionEntitlementService;
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

class StudentElectionAddonTest extends TransportHttpTestCase
{
    protected function fixture(string $direction = 'to_school'): array
    {
        $school = SchoolBuilder::create(['operating_mode' => 'day', 'lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi']);
        $role = RoleBuilder::create();
        $row = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($row->id);

        return compact('school', 'actor');
    }

    private function service(): StudentElectionAddonService
    {
        return app(StudentElectionAddonService::class);
    }

    private function owner(): User
    {
        $role = DB::table('roles')->whereNull('school_id')->where('role_name', 'Platform Owner')->where('system_role', true)->where('active', true)->value('id');
        $this->assertNotNull($role);
        $owner = User::create(['id' => (string) Str::uuid(), 'school_id' => null, 'role_id' => $role,
            'username' => 'election-addon-owner-'.Str::lower(Str::random(12)), 'password_hash' => Hash::make('Correct#Password99'),
            'email' => Str::lower(Str::random(16)).'@example.test', 'first_name' => 'Election', 'last_name' => 'Owner', 'active' => true,
            'first_login' => false, 'auth_generation' => 1, 'is_deleted' => false, 'email_verified_at' => now(), 'activated_at' => now(), 'mfa_enabled' => true]);
        foreach (['access_administrator_portal', 'access_platform_administration', 'manage_student_election_subscriptions', 'view_student_election_subscriptions'] as $p) {
            $this->grant($owner, $p);
        }

        return $owner;
    }

    private function activation(User $owner, array $f, array $extra = []): array
    {
        return $extra + ['request_id' => (string) Str::uuid(), 'expected_version' => $this->service()->platformStatus($owner, $f['school']->id)['version'],
            'starts_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'commercial_timezone' => 'Africa/Nairobi',
            'evidence_reference' => 'TEST-ELECTION-'.Str::upper(Str::random(12)), 'reason' => 'Isolated manual attestation of already checked commercial evidence.'];
    }

    private function base(array $f): string
    {
        $package = (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('subscription_packages')->insert(['id' => $package, 'package_name' => 'BASIC-ISOLATED-'.Str::random(12), 'amount' => 1000, 'duration_months' => 4, 'active' => true]);
        $date = CarbonImmutable::now('UTC');
        DB::table('school_subscriptions')->insert(['id' => $id, 'school_id' => $f['school']->id, 'package_id' => $package,
            'start_date' => $date->subDay()->toDateString(), 'expiry_date' => $date->addDays(20)->toDateString(), 'grace_end_date' => $date->addDays(34)->toDateString(), 'status' => 'ACTIVE']);

        return $id;
    }

    private function normal(array $f): ?array
    {
        return app(StudentElectionEntitlementService::class)->normal($f['actor']);
    }

    private function annualDenied(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected commercial rejection.');
        } catch (ValidationException) {
            $this->assertSame(422, $status);
        } catch (AuthorizationException) {
            $this->assertSame(403, $status);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_no_school_role_is_automatically_granted_subscription_permissions(): void
    {
        $f = $this->fixture();
        foreach (['manage_student_election_subscriptions', 'view_student_election_subscriptions', 'view_own_student_election_subscription'] as $name) {
            $p = DB::table('permissions')->where('permission_name', $name)->first();
            $this->assertNotNull($p);
            $this->assertFalse(DB::table('role_permissions')->where('role_id', $f['actor']->role_id)->where('permission_id', $p->id)->exists());
        }
    }

    public function test_exact_twelve_months_are_derived_without_changing_base_package_or_portals(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $owner = $this->owner();
        $before = DB::table('school_subscriptions')->where('id', $base)->first();
        $settings = DB::table('school_settings')->where('school_id', $f['school']->id)->get()->toJson();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $start = CarbonImmutable::parse($p['period']['starts_at'])->setTimezone('Africa/Nairobi');
        $this->assertTrue($start->addMonthsNoOverflow(12)->equalTo(CarbonImmutable::parse($p['period']['ends_at'])));
        $this->assertEquals($before, DB::table('school_subscriptions')->where('id', $base)->first());
        $this->assertSame($settings, DB::table('school_settings')->where('school_id', $f['school']->id)->get()->toJson());
        $this->assertNotNull($this->normal($f));
    }

    public function test_leap_day_activation_ends_on_next_february_28(): void
    {
        CarbonImmutable::setTestNow('2028-02-29T09:00:00Z');
        $f = $this->fixture();
        $owner = $this->owner();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $this->assertSame('2029-02-28T12:00:00+03:00', CarbonImmutable::parse($p['period']['ends_at'])->setTimezone('Africa/Nairobi')->toIso8601String());
    }

    public function test_dst_timezone_preserves_calendar_anniversary(): void
    {
        CarbonImmutable::setTestNow('2027-03-28T00:30:00Z');
        $f = $this->fixture();
        $owner = $this->owner();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['commercial_timezone' => 'Europe/London']));
        $this->assertSame('2028-03-28T00:30:00+01:00', CarbonImmutable::parse($p['period']['ends_at'])->setTimezone('Europe/London')->toIso8601String());
    }

    public function test_clients_cannot_choose_an_arbitrary_end_date(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->annualDenied(422, fn () => $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['ends_at' => '2030-01-01T00:00:00+00:00'])));
    }

    public function test_same_request_replays_without_another_period_or_audit(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $data = $this->activation($owner, $f);
        $a = $this->service()->issue($owner, $f['school']->id, $data);
        $b = $this->service()->issue($owner, $f['school']->id, $data);
        $this->assertSame($a['period']['id'], $b['period']['id']);
        $this->assertTrue($b['replayed']);
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school']->id)->where('action', 'student_election_addon_activate')->count());
    }

    public function test_request_cannot_be_reused_with_changed_evidence(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $data = $this->activation($owner, $f);
        $this->service()->issue($owner, $f['school']->id, $data);
        $data['reason'] = 'Different commercial evidence cannot reuse a request.';
        $this->annualDenied(409, fn () => $this->service()->issue($owner, $f['school']->id, $data));
    }

    public function test_school_actor_cannot_attest_own_paid_activation(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $data = $this->activation($owner, $f);
        $this->annualDenied(403, fn () => $this->service()->issue($f['actor'], $f['school']->id, $data));
    }

    public function test_backdated_activation_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->annualDenied(422, fn () => $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['starts_at' => CarbonImmutable::now('UTC')->subSecond()->toIso8601String()])));
    }

    public function test_contiguous_renewal_adds_one_calendar_year(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $a = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $b = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['starts_at' => CarbonImmutable::parse($a['period']['ends_at'])->toIso8601String()]));
        $this->assertSame($a['period']['id'], $b['previous_id']);
        $this->assertTrue(CarbonImmutable::parse($a['period']['ends_at'])->equalTo(CarbonImmutable::parse($b['period']['starts_at'])));
    }

    public function test_overlapping_renewal_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $this->annualDenied(409, fn () => $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f)));
    }

    public function test_custom_package_name_does_not_grant_enterprise_elections(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $package = DB::table('school_subscriptions')->where('id', $base)->value('package_id');
        DB::table('subscription_packages')->where('id', $package)->update(['package_name' => 'ENTERPRISE-ISOLATED-'.Str::random(12)]);
        $this->assertNull($this->normal($f));
    }

    public function test_enterprise_includes_elections_without_an_addon_period(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $package = DB::table('school_subscriptions')->where('id', $base)->value('package_id');
        DB::table('subscription_packages')->where('id', $package)->update(['package_name' => 'Enterprise']);

        $basis = $this->normal($f);
        $this->assertNotNull($basis);
        $this->assertNull($basis['period_id']);
        $this->assertSame($base, $basis['main_subscription_id']);
        $owner = $this->owner();
        $this->annualDenied(409, fn () => $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f)));

        DB::table('school_subscriptions')->where('id', $base)->update(['expiry_date' => CarbonImmutable::now('UTC')->subDays(2)->toDateString()]);
        $this->assertNull($this->normal($f));
    }

    public function test_addon_alone_does_not_replace_main_subscription(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $this->assertNull($this->normal($f));
    }

    public function test_start_inclusive_and_end_exclusive(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $owner = $this->owner();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $end = CarbonImmutable::parse($p['period']['ends_at']);
        DB::table('school_subscriptions')->where('id', $base)->update(['expiry_date' => $end->addDay()->toDateString()]);
        $this->assertNotNull($this->normal($f));
        CarbonImmutable::setTestNow($end->subSecond());
        $this->assertNotNull($this->normal($f));
        CarbonImmutable::setTestNow($end);
        $this->assertNull($this->normal($f));
    }

    public function test_main_subscription_expiry_refuses_normal_access(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        DB::table('school_subscriptions')->where('id', $base)->update(['expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);
        $this->assertNull($this->normal($f));
    }

    public function test_future_period_does_not_grant_early_access(): void
    {
        $f = $this->fixture();
        $this->base($f);
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f, ['starts_at' => CarbonImmutable::now('UTC')->addDay()->toIso8601String()]));
        $this->assertNull($this->normal($f));
    }

    public function test_main_grace_is_bounded_by_its_existing_end_date(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        DB::table('school_subscriptions')->where('id', $base)->update(['status' => 'GRACE_PERIOD']);
        $this->assertNotNull($this->normal($f));
        DB::table('school_subscriptions')->where('id', $base)->update(['grace_end_date' => CarbonImmutable::now()->subDay()->toDateString()]);
        $this->assertNull($this->normal($f));
    }

    public function test_read_only_school_has_no_normal_commercial_access(): void
    {
        $f = $this->fixture();
        $this->base($f);
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'read_only']);
        $this->assertNull($this->normal($f));
    }

    public function test_stale_commercial_version_is_rejected_before_renewal(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $old = $this->activation($owner, $f);
        $period = $this->service()->issue($owner, $f['school']->id, $old);
        $old['request_id'] = (string) Str::uuid();
        $old['evidence_reference'] = 'TEST-NEW-'.Str::random(12);
        $old['starts_at'] = CarbonImmutable::parse($period['period']['ends_at'])->toIso8601String();
        $this->annualDenied(409, fn () => $this->service()->issue($owner, $f['school']->id, $old));
    }

    public function test_tenant_models_cannot_be_spoofed(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $actor = clone $f['actor'];
        $actor->school_id = $other['school']->id;
        $this->annualDenied(403, fn () => app(StudentElectionEntitlementService::class)->requireNormal($actor));
    }

    public function test_periods_cannot_be_modified_or_deleted(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $p) {
                    $q = DB::table('student_election_addon_periods')->where('id', $p['period']['id']);
                    if ($operation === 'update') {
                        $q->update(['reason' => 'Changed immutable commercial evidence.']);
                    } else {
                        $q->delete();
                    }
                });
                $this->fail('Immutable period changed.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_audit_failure_rolls_back_activation(): void
    {
        $f = $this->fixture();
        $owner = $this->owner();
        $data = $this->activation($owner, $f);
        $this->mock(AdministratorAuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Deliberate audit failure'));
        try {
            $this->service()->issue($owner, $f['school']->id, $data);
            $this->fail('Audit failure ignored.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate audit failure', $e->getMessage());
        }
        $this->assertSame(0, DB::table('student_election_addon_periods')->where('school_id', $f['school']->id)->count());
    }

    public function test_shared_evaluator_preserves_both_public_contracts_at_period_boundaries(): void
    {
        $f = $this->fixture();
        $this->base($f);
        $owner = $this->owner();
        $p = $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        $start = CarbonImmutable::parse($p['period']['starts_at']);
        $end = CarbonImmutable::parse($p['period']['ends_at']);
        // Keep the main subscription eligible so these cases isolate the add-on boundary.
        DB::table('school_subscriptions')->where('school_id', $f['school']->id)->update(['expiry_date' => $end->addDay()->toDateString()]);
        foreach ([[$start->subSecond(), false], [$start, true], [$end->subSecond(), true], [$end, false]] as [$at, $eligible]) {
            CarbonImmutable::setTestNow($at);
            $basis = $this->normal($f);
            $this->assertSame($eligible, $basis !== null);
            if ($eligible) {
                $operational = app(StudentElectionCommercialAccess::class)->normal($f['school']->id);
                $this->assertSame($basis['period_id'], $operational['period_id']);
                $this->assertSame($basis['main_subscription_id'], $operational['main_subscription_id']);
                $this->assertSame($basis['checked_at'], $operational['checked_at']);
                $this->assertSame('normal', $basis['mode']);
                $this->assertArrayHasKey('ends_at', $basis);
                $this->assertSame(['period_id', 'main_subscription_id', 'checked_at'], array_keys($operational));
            } else {
                $this->annualDenied(403, fn () => app(StudentElectionCommercialAccess::class)->normal($f['school']->id));
            }
        }
    }

    public function test_shared_evaluator_preserves_main_subscription_and_school_denials(): void
    {
        $f = $this->fixture();
        $base = $this->base($f);
        $owner = $this->owner();
        $this->service()->issue($owner, $f['school']->id, $this->activation($owner, $f));
        DB::table('school_subscriptions')->where('id', $base)->update(['expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);
        $this->assertNull($this->normal($f));
        $this->annualDenied(403, fn () => app(StudentElectionCommercialAccess::class)->normal($f['school']->id));
        DB::table('school_subscriptions')->where('id', $base)->update(['status' => 'GRACE_PERIOD', 'grace_end_date' => CarbonImmutable::now()->addDay()->toDateString()]);
        $this->assertNotNull($this->normal($f));
        $this->assertNotEmpty(app(StudentElectionCommercialAccess::class)->normal($f['school']->id));
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'read_only']);
        $this->assertNull($this->normal($f));
        $this->annualDenied(403, fn () => app(StudentElectionCommercialAccess::class)->normal($f['school']->id));
    }
}
