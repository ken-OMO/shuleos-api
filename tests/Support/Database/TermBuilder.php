<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TermBuilder
{
    public static function create(
        object $school,
        object $academicYear,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
            'term_name' => 'Term 2',
            'start_date' => '2026-05-01',
            'end_date' => '2026-08-01',
            'active' => true,
        ], $attributes);

        DB::table('terms')->insert($record);

        return (object) $record;
    }
}
