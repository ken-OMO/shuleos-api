<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorPortalAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PlatformSchoolOnboardingService
{
    private const TEMPORARY_PASSWORD_HOURS = 24;

    private const IDENTIFIER_ATTEMPTS = 10;

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit
    ) {}

    public function onboard(
        User $actor,
        array $data
    ): array {
        $this->access->requirePlatform(
            $actor,
            'onboard_schools'
        );

        $schoolAdminRoleId = DB::table('roles')
            ->where(
                'role_name',
                'School Admin'
            )
            ->whereNull(
                'school_id'
            )
            ->where(
                'system_role',
                true
            )
            ->where(
                'active',
                true
            )
            ->value('id');

        if (! $schoolAdminRoleId) {
            throw new RuntimeException(
                'Required School Admin role is unavailable.'
            );
        }

        return DB::transaction(
            function () use (
                $actor,
                $data,
                $schoolAdminRoleId
            ) {
                $loginPrefix = $this->loginPrefix(
                    $data['school_name']
                );

                $schoolCode = $this->schoolCode();

                $username = $this->adminUsername(
                    $loginPrefix
                );

                $temporaryPassword =
                    $this->temporaryPassword();

                $school = School::create([
                    'id' => (string) Str::uuid(),

                    'school_name' => trim(
                        strip_tags(
                            $data['school_name']
                        )
                    ),

                    'school_code' => $schoolCode,

                    'login_prefix' => $loginPrefix,

                    'active' => true,

                    'is_deleted' => false,

                    'lifecycle_state' => 'onboarding',

                    'lifecycle_version' => 1,

                    'timezone' => $data['timezone']
                        ?? 'Africa/Nairobi',

                    'locale' => $data['locale']
                        ?? 'en',
                ]);

                $admin = User::create([
                    'id' => (string) Str::uuid(),

                    'school_id' => $school->id,

                    'role_id' => $schoolAdminRoleId,

                    'username' => $username,

                    'password_hash' => Hash::make(
                        $temporaryPassword
                    ),

                    'email' => strtolower(
                        trim(
                            $data['admin']['email']
                        )
                    ),

                    'first_name' => trim(
                        strip_tags(
                            $data['admin']['first_name']
                        )
                    ),

                    'last_name' => trim(
                        strip_tags(
                            $data['admin']['last_name']
                        )
                    ),

                    'active' => true,

                    'first_login' => true,

                    'temporary_password' => true,

                    'temporary_password_expires_at' => now()->addHours(
                        self::TEMPORARY_PASSWORD_HOURS
                    ),

                    'force_password_reset_at' => now(),

                    'email_verified_at' => null,

                    'activated_at' => null,

                    'auth_generation' => 1,

                    'is_deleted' => false,
                ]);

                $this->audit->record(
                    $actor,
                    'platform_school_onboarded',
                    'schools',
                    $school->id,
                    [],
                    [
                        'school_id' => $school->id,

                        'initial_admin_user_id' => $admin->id,

                        'lifecycle_state' => 'onboarding',
                    ],
                    $school->id
                );

                return [
                    'school' => [
                        'id' => $school->id,

                        'school_name' => $school->school_name,

                        'school_code' => $school->school_code,

                        'login_prefix' => $school->login_prefix,

                        'lifecycle_state' => $school->lifecycle_state,
                    ],

                    'initial_admin' => [
                        'id' => $admin->id,

                        'username' => $admin->username,

                        'email' => $admin->email,

                        'temporary_password' => $temporaryPassword,

                        'first_login' => true,

                        'temporary_password_expires_at' => $admin->temporary_password_expires_at,
                    ],
                ];
            }
        );
    }

    private function schoolCode(): string
    {
        for (
            $attempt = 0;
            $attempt < self::IDENTIFIER_ATTEMPTS;
            $attempt++
        ) {
            $code = 'SCH'
                .now()->format('ymd')
                .Str::upper(
                    Str::random(8)
                );

            $exists = DB::table('schools')
                ->where(
                    'school_code',
                    $code
                )
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        throw new RuntimeException(
            'Unable to generate a unique school code.'
        );
    }

    private function adminUsername(
        string $loginPrefix
    ): string {
        $prefix = Str::lower(
            preg_replace(
                '/[^A-Za-z0-9]/',
                '',
                $loginPrefix
            ) ?: 'school'
        );

        for (
            $attempt = 0;
            $attempt < self::IDENTIFIER_ATTEMPTS;
            $attempt++
        ) {
            $username = $prefix
                .'.admin.'
                .Str::lower(
                    Str::random(8)
                );

            $exists = DB::table('users')
                ->where(
                    'username',
                    $username
                )
                ->exists();

            if (! $exists) {
                return $username;
            }
        }

        throw new RuntimeException(
            'Unable to generate a unique administrator username.'
        );
    }

    private function temporaryPassword(): string
    {
        return Str::random(24)
            .'#'
            .Str::upper(
                Str::random(4)
            )
            .'9a';
    }

    private function loginPrefix(
        string $schoolName
    ): string {
        $words = preg_split(
            '/\s+/',
            trim(
                strip_tags(
                    $schoolName
                )
            )
        );

        $prefix = collect($words)
            ->filter()
            ->map(
                fn ($word) => Str::upper(
                    Str::substr(
                        $word,
                        0,
                        1
                    )
                )
            )
            ->implode('');

        return Str::substr(
            $prefix ?: 'SCH',
            0,
            12
        );
    }
}
