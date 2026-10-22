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
            ->where('permission_name', 'create_exam')->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'id' => (string) Str::uuid(),
            'permission_name' => 'create_exam',
            'module_name' => 'assessment',
            'description' => 'Manage exams and exam roster configuration',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve deployed permission identities and role assignments.
    }
};
