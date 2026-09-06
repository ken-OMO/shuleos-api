<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MarkEntryPermissionBuilder
{
    public static function create(
        object $exam,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'exam_id' => $exam->id,
            'role_name' => 'teacher',
            'active' => true,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHour(),
            'is_deleted' => false,
        ], $attributes);

        DB::table('mark_entry_permissions')->insert($record);

        return (object) $record;
    }
}
