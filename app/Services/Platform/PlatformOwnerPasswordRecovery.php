<?php

namespace App\Services\Platform;

use App\Models\AuthenticationChallenge;
use App\Models\AuthenticationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PlatformOwnerPasswordRecovery
{
    public function recover(string $email, string $password): void
    {
        $email = Str::lower(trim($email));
        Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:16', 'max:72', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/'],
        ], [
            'password.regex' => 'Use lowercase, uppercase, numeric and symbol characters.',
        ])->validate();

        if (strlen($password) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must be at most 72 bytes.']);
        }

        DB::transaction(function () use ($email, $password) {
            $role = DB::table('roles')->where('role_name', 'Platform Owner')
                ->whereNull('school_id')->where('system_role', true)->where('active', true)
                ->lockForUpdate()->sole();
            $owner = User::query()->where('role_id', $role->id)->lockForUpdate()->sole();
            if ($owner->school_id !== null || ! $owner->active || $owner->is_deleted || $owner->suspended_at
                || Str::lower((string) $owner->email) !== $email) {
                throw new RuntimeException('Eligible Platform Owner account not found.');
            }

            $lower = Str::lower($password);
            $emailName = Str::before($email, '@');
            if (str_contains($lower, Str::lower($owner->username))
                || (strlen($emailName) >= 4 && str_contains($lower, $emailName))
                || Hash::check($password, $owner->password_hash)) {
                throw ValidationException::withMessages(['password' => 'Choose a new password that excludes the account identifiers.']);
            }

            $now = now();
            $owner->forceFill([
                'password_hash' => Hash::make($password),
                'first_login' => true,
                'temporary_password' => true,
                'temporary_password_expires_at' => $now->copy()->addHours(24),
                'force_password_reset_at' => $now,
                'failed_login_attempts' => 0,
                'last_failed_login' => null,
                'account_locked_until' => null,
                'auth_generation' => (int) $owner->auth_generation + 1,
            ])->save();

            AuthenticationChallenge::query()->where('user_id', $owner->id)->whereNull('school_id')
                ->whereNull('consumed_at')->update(['consumed_at' => $now, 'updated_at' => $now]);
            AuthenticationSession::query()->where('user_id', $owner->id)->whereNull('school_id')
                ->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'revocation_reason' => 'platform_owner_password_recovery',
                    'updated_at' => $now,
                ]);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'school_id' => null, 'user_id' => $owner->id,
                'module' => 'Authentication', 'action' => 'platform_owner_password_recovered',
                'table_name' => 'users', 'record_id' => $owner->id,
                'description' => 'Platform Owner temporary password issued from the application console.',
                'user_agent' => 'artisan-console', 'created_at' => $now,
            ]);
        }, 3);
    }
}
