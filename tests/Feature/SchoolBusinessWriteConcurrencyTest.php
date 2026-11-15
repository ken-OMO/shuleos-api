<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\SchoolBusinessConcurrencyRunner;
use Tests\TestCase;

class SchoolBusinessWriteConcurrencyTest extends TestCase
{
    public static function restrictions(): array
    {
        return ['subscription update' => ['expire'], 'new latest row' => ['supersede'], 'school lifecycle' => ['restrict']];
    }

    #[DataProvider('restrictions')]
    public function test_waiting_write_rechecks_committed_restriction(string $action): void
    {
        $this->withFixture(function (object $school, object $subscription) use ($action): void {
            $pending = ['id' => (string) Str::uuid(), 'school_id' => $school->id,
                'status' => 'PENDING_PAYMENT', 'created_at' => now()->addSecond()->toDateTimeString()];
            $result = (new SchoolBusinessConcurrencyRunner)->run(
                ['action' => $action, 'school_id' => $school->id, 'subscription_id' => $subscription->id, 'subscription' => $pending],
                ['action' => 'write', 'school_id' => $school->id]
            );
            $this->assertTrue($result['contention_verified']);
            $this->assertSame('finished', $result['first']['event']);
            $this->assertSame('rejected', $result['second']['event']);
            $this->assertSame(403, $result['second']['status']);
            $this->assertSame('Before', DB::table('schools')->where('id', $school->id)->value('short_name'));
        });
    }

    public function test_subscription_sql_writer_waits_until_authorized_business_write_commits(): void
    {
        $this->withFixture(function (object $school, object $subscription): void {
            $result = (new SchoolBusinessConcurrencyRunner)->run(
                ['action' => 'write', 'school_id' => $school->id],
                ['action' => 'expire', 'school_id' => $school->id, 'subscription_id' => $subscription->id]
            );
            $this->assertTrue($result['contention_verified']);
            $this->assertSame('finished', $result['first']['event']);
            $this->assertSame('finished', $result['second']['event']);
            $this->assertSame('Written', DB::table('schools')->where('id', $school->id)->value('short_name'));
            $this->assertSame('2000-01-01', DB::table('school_subscriptions')->where('id', $subscription->id)->value('expiry_date'));
        });
    }

    private function withFixture(\Closure $test): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'shuleos_assessment_fk_test'
            || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)
            || app()->configurationIsCached() || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Committed fixtures require the isolated PostgreSQL testing database.');
        }
        $school = SchoolBuilder::create(['lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi', 'short_name' => 'Before']);
        $subscription = MainSubscriptionBuilder::activeFor($school->id);
        try {
            $test($school, $subscription);
        } finally {
            DB::table('school_subscriptions')->where('school_id', $school->id)->delete();
            DB::table('schools')->where('id', $school->id)->delete();
            DB::table('subscription_packages')->where('id', $subscription->package_id)->delete();
        }
    }
}
