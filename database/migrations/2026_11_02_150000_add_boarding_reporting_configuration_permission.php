<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'permission_name' => 'configure_boarding_reporting',
            'module_name' => 'boarding',
            'description' => 'Configure boarding reporting policies and materialize daily obligations.',
            'created_at' => now(),
        ]);

        if (! DB::table('permissions')
            ->where('permission_name', 'configure_boarding_reporting')->exists()) {
            throw new RuntimeException('Boarding reporting configuration permission is missing.');
        }
    }

    public function down(): void
    {
        // Retain permission identities and any explicitly approved role grants.
    }
};
