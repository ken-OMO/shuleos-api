<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TeacherBuilder
{
    public static function create(
        object $school,
        object $user,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'user_id' => $user->id,
            'tsc_no' => 'TSC-'.$suffix,
            'staff_no' => 'STF-'.$suffix,
            'designation' => 'Teacher',
            'employment_type' => 'Permanent',
            'active' => true,
            'is_deleted' => false,
        ], $attributes);

        DB::table('teachers')->insert($record);

        return (object) $record;
    }
}
