<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $levels = [
            'Early Years Education' => 1,
            'Lower Primary' => 2,
            'Upper Primary' => 3,
            'Junior School' => 4,
        ];

        foreach ($levels as $name => $order) {
            $exists = DB::table('education_levels')
                ->whereRaw('lower(trim(level_name)) = ?', [strtolower($name)])
                ->exists();

            if (! $exists) {
                DB::table('education_levels')->insert([
                    'id' => (string) Str::uuid(),
                    'level_name' => $name,
                    'level_order' => $order,
                    'active' => true,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Education levels may be referenced by school grades. Use a forward migration.');
    }
};
