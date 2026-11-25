<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $areas = [
            'Language Activities' => ['LA'],
            'Mathematical Activities' => ['MA'],
            'Environmental Activities' => ['EA'],
            'Creative Activities' => ['CA', 'Creative and Psychomotor Activities'],
            'English Activities' => ['ENG-A'],
            'Kiswahili' => ['KIS'],
            'Mathematics Activities' => ['MATH-A'],
            'English' => ['ENG'],
            'Mathematics' => ['MATH'],
            'Science and Technology' => ['SCI-TECH'],
            'Agriculture' => ['AGR'],
            'Social Studies' => ['SST'],
            'Creative Arts' => ['ARTS'],
            'Integrated Science' => ['INT-SCI'],
            'Pre-Technical Studies' => ['PRE-TECH'],
            'Christian Religious Education' => ['CRE', 'CRE'],
            'Islamic Religious Education' => ['IRE', 'IRE'],
            'Hindu Religious Education' => ['HRE', 'HRE'],
        ];

        foreach ($areas as $name => $entry) {
            $shortName = $entry[0];
            $names = array_map(Str::lower(...), array_filter([$name, $entry[1] ?? null]));
            $exists = DB::table('learning_areas')
                ->whereIn(DB::raw('lower(trim(learning_area_name))'), $names)
                ->exists();

            if (! $exists) {
                DB::table('learning_areas')->insert([
                    'id' => (string) Str::uuid(),
                    'learning_area_name' => $name,
                    'short_name' => $shortName,
                    'active' => true,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Learning areas may be referenced by school records. Use a forward migration.');
    }
};
