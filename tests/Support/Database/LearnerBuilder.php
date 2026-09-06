<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LearnerBuilder
{
    public static function create(
        object $school,
        object $grade,
        object $stream,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'admission_no' => 'ADM-'.strtoupper($suffix),
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'active' => true,
            'portal_enabled' => true,
            'is_deleted' => false,
        ], $attributes);

        DB::table('learners')->insert($record);

        return (object) $record;
    }
}
