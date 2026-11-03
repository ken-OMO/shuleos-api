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
            'record_boarding_operations' => 'Record Boarding Operations',
            'view_boarding_operations' => 'View Boarding Operations',
            'review_boarding_reports' => 'Review Boarding Reports',
        ] as $name => $description) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'permission_name' => $name,
                'module_name' => 'boarding',
                'description' => $description,
                'created_at' => now(),
            ]);

            if (! DB::table('permissions')->where('permission_name', $name)->exists()) {
                throw new RuntimeException('Boarding reporting permission is unavailable.');
            }
        }

        // Deliberately no role or responsibility-based automatic grants.
    }

    public function down(): void
    {
        // Retain permissions that administrators may have assigned explicitly.
    }
};
