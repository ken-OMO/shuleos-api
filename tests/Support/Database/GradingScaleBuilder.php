<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GradingScaleBuilder
{
    public static function create(
        object $gradingSystem,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'grading_system_id' => $gradingSystem->id,
            'grade_code' => 'EE',
            'grade_description' => 'Exceeding Expectation',
            'min_score' => 75,
            'max_score' => 100,
            'points' => null,
            'sort_order' => 1,
        ], $attributes);

        DB::table('grading_scales')->insert($record);

        return (object) $record;
    }
}
