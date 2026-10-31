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
            ->where('permission_name', 'manage_learning_areas')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'id' => (string) Str::uuid(),
            'permission_name' => 'manage_learning_areas',
            'module_name' => 'academics',
            'description' => 'Manage learning areas, allocations and teacher assignments',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve deployed permission identities and explicit role assignments.
    }
};
