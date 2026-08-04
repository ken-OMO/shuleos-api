<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleBuilder
{
    public static function create(array $attributes = []): object
    {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'role_name' => 'test_role_'.strtolower(substr(str_replace('-', '', $id), 0, 8)),
        ], $attributes);

        DB::table('roles')->insert($record);

        return (object) $record;
    }
}
