<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StreamBuilder
{
    public static function create(
        object $school,
        object $grade,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_name' => 'Test Stream '.$suffix,
            'active' => true,
        ], $attributes);

        DB::table('streams')->insert($record);

        return (object) $record;
    }
}
