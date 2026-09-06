<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GradeBuilder
{
    public static function create(
        object $school,
        ?object $educationLevel = null,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'grade_name' => 'Test Grade '.$suffix,
            'grade_order' => 1,
            'active' => true,
            'education_level_id' => $educationLevel?->id,
        ], $attributes);

        DB::table('grades')->insert($record);

        return (object) $record;
    }
}
