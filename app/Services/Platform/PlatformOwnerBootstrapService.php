<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PlatformOwnerBootstrapService
{
    public function bootstrap(
        array $data,
        string $temporaryPassword
    ): User {
        $data = $this->validatedData(
            $data,
            $temporaryPassword
        );

        return DB::transaction(function () use (
            $data,
            $temporaryPassword
        ) {
            /*
             * Lock the canonical Platform Owner role row.
             *
             * This serializes competing bootstrap attempts so two
             * concurrent commands cannot create two Platform Owners.
             */
            $role = DB::table('roles')
                ->where('role_name', 'Platform Owner')
                ->whereNull('school_id')
                ->where('system_role', true)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $role) {
                throw new RuntimeException(
                    'The canonical active Platform Owner system role was not found.'
                );
            }

            /*
             * There must only be one root Platform Owner identity.
             *
             * We deliberately refuse even if an existing owner has
             * later been disabled or soft-deleted. Root ownership
             * recovery / transfer must be an explicit future workflow.
             */
            $existingOwner = User::query()
                ->where('role_id', $role->id)
                ->lockForUpdate()
                ->first();

            if ($existingOwner) {
                throw new RuntimeException(
                    'A Platform Owner identity already exists. Bootstrap has been refused.'
                );
            }

            $emailExists = User::query()
                ->whereRaw(
                    'LOWER(email) = ?',
                    [$data['email']]
                )
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => [
                        'The email address is already assigned to another account.',
                    ],
                ]);
            }

            $usernameExists = User::query()
                ->whereRaw(
                    'LOWER(username) = ?',
                    [$data['username']]
                )
                ->exists();

            if ($usernameExists) {
                throw ValidationException::withMessages([
                    'username' => [
                        'The username is already assigned to another account.',
                    ],
                ]);
            }

            /*
             * Root bootstrap must leave an audit record.
             */
            if (! Schema::hasTable('audit_logs')) {
                throw new RuntimeException(
                    'Security audit storage is unavailable. Platform Owner bootstrap has been refused.'
                );
            }

            $user = new User;

            $user->forceFill([
                'id' => (string) Str::uuid(),

                /*
                 * Platform identities NEVER belong to a school.
                 */
                'school_id' => null,

                'role_id' => (string) $role->id,
                'username' => $data['username'],

                /*
                 * Plaintext password is never persisted.
                 */
                'password_hash' => Hash::make(
                    $temporaryPassword
                ),

                'email' => $data['email'],
                'phone' => null,

                'first_name' => $data['first_name'],
                'middle_name' => null,
                'last_name' => $data['last_name'],

                'active' => true,
                'is_deleted' => false,

                /*
                 * Initial security state.
                 */
                'first_login' => true,
                'temporary_password' => true,
                'temporary_password_expires_at' => now()
                    ->addHours(24),

                /*
                 * Email possession is NOT assumed simply because an
                 * operator typed the address into the console.
                 *
                 * The later OTP flow will verify possession.
                 */
                'email_verified_at' => null,

                /*
                 * Force password replacement after successful
                 * first-login OTP verification.
                 */
                'force_password_reset_at' => now(),

                'password_changed_at' => null,
                'activated_at' => null,

                'failed_login_attempts' => 0,
                'last_failed_login' => null,
                'account_locked_until' => null,
                'suspended_at' => null,

                /*
                 * Session / invitation invalidation counters.
                 */
                'auth_generation' => 1,
                'invitation_generation' => 1,

                'mfa_enabled' => false,
            ]);

            $user->save();

            /*
             * No password, password hash, OTP, recovery token,
             * or other secret is written to the audit trail.
             */
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => null,
                'user_id' => $user->id,
                'module' => 'Authentication',
                'action' => 'platform_owner_bootstrapped',
                'table_name' => 'users',
                'record_id' => $user->id,
                'description' => 'Initial Platform Owner identity bootstrapped from the application console.',
                'old_values' => null,
                'new_values' => null,
                'ip_address' => null,
                'user_agent' => 'artisan-console',
                'created_at' => now(),
            ]);

            return $user->fresh([
                'role',
            ]);
        }, 3);
    }

    private function validatedData(
        array $data,
        string $temporaryPassword
    ): array {
        $normalized = [
            'first_name' => trim(
                (string) ($data['first_name'] ?? '')
            ),

            'last_name' => trim(
                (string) ($data['last_name'] ?? '')
            ),

            'email' => Str::lower(
                trim(
                    (string) ($data['email'] ?? '')
                )
            ),

            'username' => Str::lower(
                trim(
                    (string) ($data['username'] ?? '')
                )
            ),

            'password' => $temporaryPassword,
        ];

        $validator = Validator::make(
            $normalized,
            [
                'first_name' => [
                    'required',
                    'string',
                    'max:100',
                ],

                'last_name' => [
                    'required',
                    'string',
                    'max:100',
                ],

                'email' => [
                    'required',
                    'email:rfc',
                    'max:255',
                ],

                'username' => [
                    'required',
                    'string',
                    'min:3',
                    'max:100',
                    'regex:/\A[a-z0-9][a-z0-9._-]*\z/',
                ],

                'password' => [
                    'required',
                    'string',
                    'min:16',
                    'max:128',
                    'regex:/[a-z]/',
                    'regex:/[A-Z]/',
                    'regex:/[0-9]/',
                    'regex:/[^A-Za-z0-9]/',
                ],
            ],
            [
                'password.regex' => 'The temporary password must contain lowercase, uppercase, numeric, and symbol characters.',
            ]
        );

        $validator->after(function ($validator) use (
            $normalized
        ) {
            $password = Str::lower(
                $normalized['password']
            );

            $username = Str::lower(
                $normalized['username']
            );

            if (
                $username !== ''
                && str_contains(
                    $password,
                    $username
                )
            ) {
                $validator->errors()->add(
                    'password',
                    'The temporary password must not contain the username.'
                );
            }

            $emailLocalPart = Str::before(
                $normalized['email'],
                '@'
            );

            if (
                strlen($emailLocalPart) >= 4
                && str_contains(
                    $password,
                    Str::lower($emailLocalPart)
                )
            ) {
                $validator->errors()->add(
                    'password',
                    'The temporary password must not contain the email identifier.'
                );
            }
        });

        $validated = $validator->validate();

        unset($validated['password']);

        return $validated;
    }
}
