<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'manage_transport' => 'Manage transport configuration',
            'manage_transport_enrolments' => 'Manage transport enrolments',
            'view_transport_operations' => 'View transport operations',
            'record_transport_journeys' => 'Record transport journeys',
            'authorize_transport_handover' => 'Authorize transport handovers',
            'submit_transport_daily_reports' => 'Submit transport daily reports',
            'review_transport_daily_reports' => 'Review transport daily reports',
        ] as $name => $description) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'permission_name' => $name,
                'module_name' => 'transport',
                'description' => $description,
                'created_at' => now(),
            ]);

            if (! DB::table('permissions')
                ->where('permission_name', $name)
                ->where('module_name', 'transport')
                ->exists()) {
                throw new RuntimeException('Transport permission definition conflicts.');
            }
        }

        // Administrators assign permissions explicitly; no automatic grants.
    }

    public function down(): void
    {
        // Retain permission identities and any explicit administrator grants.
    }
};
