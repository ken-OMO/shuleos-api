<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class UserBuilder
{
    public static function create(
        object $school,
        object $role,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();
        $suffix = strtolower(substr(str_replace('-', '', $id), 0, 8));

        $record = array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'role_id' => $role->id,
            'username' => 'test_user_'.$suffix,
            'password_hash' => Hash::make('Password123!'),
            'email' => 'test_'.$suffix.'@example.test',
            'first_login' => false,
            'active' => true,
            'first_name' => 'Test',
            'last_name' => 'User',
            'mfa_enabled' => false,
            'is_deleted' => false,
            'failed_login_attempts' => 0,
        ], $attributes);

        DB::table('users')->insert($record);

        return (object) $record;
    }
}
