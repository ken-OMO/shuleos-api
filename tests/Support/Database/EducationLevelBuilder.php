<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EducationLevelBuilder
{
    public static function create(array $attributes = []): object
    {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'level_name' => 'Test Level '.$suffix,
            'level_order' => 1,
            'active' => true,
        ], $attributes);

        DB::table('education_levels')->insert($record);

        return (object) $record;
    }
}
