<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'id' => (string) Str::uuid(),
            'permission_name' => 'generate_report_cards',
            'module_name' => 'assessment',
            'description' => 'Generate and access report cards',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve deployed permission identities and role assignments.
    }
};
