<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LearningAreaBuilder
{
    public static function create(array $attributes = []): object
    {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'learning_area_name' => 'Test Learning Area '.$suffix,
            'short_name' => 'TLA',
            'is_core' => true,
            'is_examined' => true,
            'is_custom' => false,
            'active' => true,
        ], $attributes);

        DB::table('learning_areas')->insert($record);

        return (object) $record;
    }
}
