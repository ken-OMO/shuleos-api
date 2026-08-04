<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GradingSystemBuilder
{
    public static function create(
        object $school,
        object $educationLevel,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'grading_name' => 'Test Grading System '.$suffix,
            'education_level_id' => $educationLevel->id,
            'uses_points' => false,
            'uses_marks' => true,
            'active' => true,
        ], $attributes);

        DB::table('grading_systems')->insert($record);

        return (object) $record;
    }
}
