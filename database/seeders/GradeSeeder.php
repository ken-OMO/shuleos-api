<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $schoolId = '9701752d-c9d0-41e3-a061-c43e1ae1e315';

        $educationLevels = DB::table('education_levels')
            ->pluck('id', 'level_name');

        $grades = [

            // Early Years Education

            [
                'name' => 'PP1',
                'order' => 1,
                'level' => 'Early Years Education',
            ],

            [
                'name' => 'PP2',
                'order' => 2,
                'level' => 'Early Years Education',
            ],

            // Lower Primary

            [
                'name' => 'Grade 1',
                'order' => 3,
                'level' => 'Lower Primary',
            ],

            [
                'name' => 'Grade 2',
                'order' => 4,
                'level' => 'Lower Primary',
            ],

            [
                'name' => 'Grade 3',
                'order' => 5,
                'level' => 'Lower Primary',
            ],

            // Upper Primary

            [
                'name' => 'Grade 4',
                'order' => 6,
                'level' => 'Upper Primary',
            ],

            [
                'name' => 'Grade 5',
                'order' => 7,
                'level' => 'Upper Primary',
            ],

            [
                'name' => 'Grade 6',
                'order' => 8,
                'level' => 'Upper Primary',
            ],

            // Junior School

            [
                'name' => 'Grade 7',
                'order' => 9,
                'level' => 'Junior School',
            ],

            [
                'name' => 'Grade 8',
                'order' => 10,
                'level' => 'Junior School',
            ],

            [
                'name' => 'Grade 9',
                'order' => 11,
                'level' => 'Junior School',
            ],

        ];

        foreach ($grades as $grade) {

            DB::table('grades')->insert([

                'id' => (string) Str::uuid(),

                'school_id' => $schoolId,

                'education_level_id' => $educationLevels[$grade['level']],

                'grade_name' => $grade['name'],

                'grade_order' => $grade['order'],

                'active' => true,

                'created_at' => now(),

            ]);
        }
    }
}
