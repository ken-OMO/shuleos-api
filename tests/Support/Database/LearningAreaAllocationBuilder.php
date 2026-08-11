<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LearningAreaAllocationBuilder
{
    public static function create(
        object $school,
        object $grade,
        object $learningArea,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'learning_area_id' => $learningArea->id,
            'lessons_per_week' => 5,
            'active' => true,
        ], $attributes);

        DB::table('learning_area_allocations')->insert($record);

        return (object) $record;
    }
}
