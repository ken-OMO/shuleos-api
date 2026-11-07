<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\UserBuilder;

final class TransportSubscriptionFixture
{
    public static function activate(string $school, ?string $endsAt = null, ?string $startsAt = null): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || $db->getDriverName() !== 'pgsql'
            || $db->getConfig('database') !== 'shuleos_assessment_fk_test' || ! empty($db->getConfig('url'))
            || $db->selectOne('SELECT current_database() AS name')->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Transport subscription fixtures require the exact isolated test database.');
        }
        $role = DB::table('roles')->whereNull('school_id')->where('role_name', 'Platform Owner')
            ->where('system_role', true)->where('active', true)->first();
        if ($role === null) {
            throw new \RuntimeException('Platform Owner role required for explicit entitlement fixture.');
        }
        $owner = UserBuilder::create((object) ['id' => null], $role);
        $now = CarbonImmutable::now('UTC');
        $real = CarbonImmutable::parse($db->selectOne('SELECT clock_timestamp() AS instant')->instant);
        $start = $startsAt ?? ($real->lessThan($now) ? $real : $now)->subDays(30)->toIso8601String();
        $end = $endsAt ?? ($real->greaterThan($now) ? $real : $now)->addDays(60)->toIso8601String();
        // Explicit raw fixture insertion exercises the real immutable-period trigger.
        // No permissions, production schools, prices or payments are activated.
        DB::table('transport_addon_periods')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school, 'request_id' => (string) Str::uuid(),
            'starts_at' => $start, 'ends_at' => $end, 'commercial_timezone' => 'UTC',
            'evidence_source' => 'platform_manual_attestation',
            'evidence_reference' => 'TEST-FIXTURE-'.strtoupper($school),
            'reason' => 'Explicit isolated Transport operational test entitlement fixture.',
            'request_fingerprint' => hash('sha256', $school), 'activated_by' => $owner->id,
        ]);
        $package = (string) Str::uuid();
        DB::table('subscription_packages')->insert([
            'id' => $package, 'package_name' => 'TRANSPORT-FIXTURE-'.$school,
            'amount' => 1000, 'duration_months' => 4, 'active' => true,
        ]);
        DB::table('school_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school, 'package_id' => $package,
            'start_date' => CarbonImmutable::parse($start)->subDay()->toDateString(),
            'expiry_date' => $now->addDays(90)->toDateString(), 'status' => 'ACTIVE',
        ]);
    }
}
