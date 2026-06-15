<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StreamSeeder extends Seeder
{
    public function run(): void
    {
        $grades = DB::table('grades')
            ->select(
                'id',
                'school_id',
                'grade_name'
            )
            ->get();

        foreach ($grades as $grade) {

            $streams = [];

            switch ($grade->grade_name) {

                case 'PP1':
                case 'PP2':
                case 'Grade 1':
                case 'Grade 2':
                case 'Grade 3':

                    $streams = [
                        'East',
                        'West',
                    ];

                    break;

                case 'Grade 4':
                case 'Grade 5':
                case 'Grade 6':

                    $streams = [
                        'East',
                        'West',
                        'North',
                    ];

                    break;

                case 'Grade 7':
                case 'Grade 8':
                case 'Grade 9':

                    $streams = [
                        'North',
                        'South',
                    ];

                    break;
            }

            foreach ($streams as $stream) {

                DB::table('streams')
                    ->updateOrInsert(

                        [

                            'grade_id' => $grade->id,

                            'stream_name' => $stream,

                        ],

                        [

                            'id' => (string) Str::uuid(),

                            'school_id' => $grade->school_id,

                            'active' => true,

                            'created_at' => now(),

                        ]

                    );
            }
        }
    }
}
