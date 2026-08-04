<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SchoolBuilder
{
    public static function create(array $attributes = []): object
    {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'school_name' => 'Test School '.substr($id, 0, 8),
            'school_code' => 'SCH-'.strtoupper(substr(str_replace('-', '', $id), 0, 8)),
            'active' => true,
            'is_deleted' => false,
        ], $attributes);

        DB::table('schools')->insert($record);

        return (object) $record;
    }
}
