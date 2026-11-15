<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\UserBuilder;

final class StudentElectionSubscriptionFixture
{
    private static function isolated(): void
    {
        StudentElectionConcurrencyFixture::assertIsolated();
        if (DB::selectOne('SELECT current_database() AS name', [], false)->name !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated writer required.');
        }
    }

    public static function activate(string $school): void
    {
        self::isolated();
        if (DB::table('schools')->where('id', $school)->value('school_name') !== 'Student Election Test School') {
            throw new \RuntimeException('Owned new Elections fixture required.');
        }
        DB::table('schools')->where('id', $school)->update(['lifecycle_state' => 'active']);
        $role = DB::table('roles')->whereNull('school_id')->where('role_name', 'Platform Owner')->where('system_role', true)->where('active', true)->first();
        if ($role === null) {
            throw new \RuntimeException('Migrated Platform Owner role required.');
        }
        $owner = UserBuilder::create((object) ['id' => null], $role);
        $override = config('testing.elections_period_starts_at');
        $start = is_string($override) ? CarbonImmutable::parse($override)->utc() : CarbonImmutable::now('UTC')->subMonthNoOverflow();
        $end = $start->addMonthsNoOverflow(12);
        DB::table('student_election_addon_periods')->insert(['id' => (string) Str::uuid(), 'school_id' => $school,
            'request_id' => (string) Str::uuid(), 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
            'commercial_timezone' => 'UTC', 'evidence_source' => 'platform_manual_attestation', 'evidence_reference' => 'TEST-ELECTION-'.strtoupper($school),
            'reason' => 'Explicit synthetic Elections entitlement fixture, no production activation.', 'request_fingerprint' => hash('sha256', $school), 'activated_by' => $owner->id]);
        $package = (string) Str::uuid();
        DB::table('subscription_packages')->insert(['id' => $package, 'package_name' => 'ELECTION-TEST-'.$school, 'amount' => 1000, 'duration_months' => 4, 'active' => true]);
        DB::table('school_subscriptions')->insert(['id' => (string) Str::uuid(), 'school_id' => $school, 'package_id' => $package,
            'start_date' => $start->subDay()->toDateString(), 'expiry_date' => $end->addDay()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now()]);
    }

    public static function clearOpenings(string $school): void
    {
        self::isolated();
        if (! DB::table('student_election_addon_periods')->where('school_id', $school)->where('evidence_reference', 'TEST-ELECTION-'.strtoupper($school))->exists()) {
            throw new \RuntimeException('Owned synthetic entitlement required.');
        }
        DB::transaction(function () use ($school) {
            DB::statement('LOCK TABLE student_election_commercial_openings IN ACCESS EXCLUSIVE MODE');
            $state = DB::selectOne("SELECT tgenabled FROM pg_trigger WHERE tgrelid = 'student_election_commercial_openings'::regclass AND tgname = 'election_commercial_opening'");
            if ($state === null || $state->tgenabled !== 'O') {
                throw new \RuntimeException('Expected enabled opening trigger.');
            }
            DB::statement('ALTER TABLE student_election_commercial_openings DISABLE TRIGGER election_commercial_opening');
            DB::table('student_election_commercial_openings')->where('school_id', $school)->delete();
            DB::statement('ALTER TABLE student_election_commercial_openings ENABLE TRIGGER election_commercial_opening');
        });
    }

    public static function cleanup(string $school): void
    {
        self::isolated();
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException('Transactional synthetic cleanup required.');
        }
        $name = DB::table('schools')->where('id', $school)->value('school_name');
        if ($name !== 'Student Election Test School' && ! str_starts_with((string) $name, 'Election application race ')) {
            throw new \RuntimeException('Unknown fixture school.');
        }
        DB::statement('LOCK TABLE student_election_commercial_openings, student_election_addon_periods IN ACCESS EXCLUSIVE MODE');
        foreach (['student_election_commercial_openings' => 'election_commercial_opening', 'student_election_addon_periods' => 'student_election_addon_period_validate'] as $table => $trigger) {
            $state = DB::selectOne('SELECT tgenabled FROM pg_trigger WHERE tgrelid = ?::regclass AND tgname = ?', [$table, $trigger]);
            if ($state === null || $state->tgenabled !== 'O') {
                throw new \RuntimeException('Expected enabled commercial evidence trigger.');
            }
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        }
        $periods = DB::table('student_election_addon_periods')->where('school_id', $school)->get();
        foreach ($periods as $p) {
            if ($p->evidence_reference !== 'TEST-ELECTION-'.strtoupper($school)) {
                throw new \RuntimeException('Refusing non-fixture entitlement cleanup.');
            }
        }
        DB::table('student_election_commercial_openings')->where('school_id', $school)->delete();
        DB::table('student_election_addon_periods')->where('school_id', $school)->delete();
        DB::table('school_subscriptions')->where('school_id', $school)->delete();
        DB::table('subscription_packages')->where('package_name', 'ELECTION-TEST-'.$school)->delete();
        foreach ($periods as $p) {
            DB::table('users')->whereNull('school_id')->where('id', $p->activated_by)->delete();
        }
        DB::statement('ALTER TABLE student_election_commercial_openings ENABLE TRIGGER election_commercial_opening');
        DB::statement('ALTER TABLE student_election_addon_periods ENABLE TRIGGER student_election_addon_period_validate');
    }
}
