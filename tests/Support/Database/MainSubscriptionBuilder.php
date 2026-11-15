<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MainSubscriptionBuilder
{
    // Explicit test-only entitlement. Never called by production provisioning.
    public static function activeFor(string $schoolId, array $attributes = []): object
    {
        $packageId = (string) Str::uuid();
        DB::table('subscription_packages')->insert([
            'id' => $packageId, 'package_name' => 'Synthetic core '.$packageId,
            'amount' => 1, 'active' => true,
        ]);
        $row = array_merge([
            'id' => (string) Str::uuid(), 'school_id' => $schoolId,
            'package_id' => $packageId, 'status' => 'ACTIVE',
            'start_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'is_trial' => false, 'created_at' => now(),
        ], $attributes);
        DB::table('school_subscriptions')->insert($row);

        return (object) $row;
    }
}
