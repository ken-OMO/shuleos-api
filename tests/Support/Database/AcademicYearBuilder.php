<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AcademicYearBuilder
{
    public static function create(
        object $school,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'year_name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
        ], $attributes);

        DB::table('academic_years')->insert($record);

        return (object) $record;
    }
}
