<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LevelLearningAreaSeeder extends Seeder
{
    public function run(): void
    {
        // Prevent duplicate seeding

        if (DB::table('level_learning_areas')->count() > 0) {

            return;
        }

        $levels = DB::table('education_levels')

            ->pluck('id', 'level_name');

        $areas = DB::table('learning_areas')

            ->pluck('id', 'learning_area_name');

        $mappings = [

            /*
            |--------------------------------------------------------------------------
            | Early Years Education
            |--------------------------------------------------------------------------
            */

            'Early Years Education' => [

                'Language Activities',

                'Mathematical Activities',

                'Environmental Activities',

                'Creative and Psychomotor Activities',

                'Religious Activities',

            ],

            /*
            |--------------------------------------------------------------------------
            | Lower Primary
            |--------------------------------------------------------------------------
            */

            'Lower Primary' => [

                'English',

                'Kiswahili',

                'Mathematics',

                'Environmental Activities',

                'Creative Arts',

            ],

            /*
            |--------------------------------------------------------------------------
            | Upper Primary
            |--------------------------------------------------------------------------
            */

            'Upper Primary' => [

                'English',

                'Kiswahili',

                'Mathematics',

                'Science and Technology',

                'Agriculture',

                'Social Studies',

                'Creative Arts',

                'Christian Religious Education',

                'Islamic Religious Education',

                'Hindu Religious Education',

            ],

            /*
            |--------------------------------------------------------------------------
            | Junior School
            |--------------------------------------------------------------------------
            */

            'Junior School' => [

                'English',

                'Kiswahili',

                'Kenya Sign Language',

                'Mathematics',

                'Integrated Science',

                'Agriculture',

                'Social Studies',

                'Pre-Technical Studies',

                'Computer Studies',

                'Christian Religious Education',

                'Islamic Religious Education',

                'Hindu Religious Education',

                'French',

                'German',

                'Arabic',

                'Mandarin',

                'Indigenous Language',

            ],

        ];

        foreach ($mappings as $level => $learningAreas) {

            foreach ($learningAreas as $area) {

                if (

                    isset($levels[$level])

                    &&

                    isset($areas[$area])

                ) {

                    DB::table(

                        'level_learning_areas'

                    )->insert([

                        'id' =>

                            (string) Str::uuid(),

                        'level_id' =>

                            $levels[$level],

                        'learning_area_id' =>

                            $areas[$area],

                        'created_at' => now(),

                    ]);
                }
            }
        }
    }
}
