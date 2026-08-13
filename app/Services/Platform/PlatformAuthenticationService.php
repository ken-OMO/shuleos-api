<?php

namespace App\Services\Platform;

use App\Mail\PlatformLoginOtpMail;
use App\Models\AuthenticationChallenge;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\Auth\AuthenticationAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Throwable;

class PlatformAuthenticationService
{
    private const PLATFORM_ROLES = [
        'Platform Owner',
        'Platform Super Administrator',
    ];

    private const OTP_TTL_MINUTES = 10;

    private const ACTIVATION_TTL_MINUTES = 10;

    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly AuthContextService $authContext,
        private readonly AuthenticationAuditService $audit,
    ) {}

    public function begin(
        string $identifier,
        string $password,
        Request $request
    ): array {
        $identifier = Str::lower(
            trim($identifier)
        );

        /*
         * Platform login has its own identity namespace.
         *
         * School users must never be resolved here.
         */
        $user = User::query()
            ->whereNull('school_id')
            ->where(function ($query) use ($identifier) {
                $query
                    ->whereRaw(
                        'LOWER(username) = ?',
                        [$identifier]
                    )
                    ->orWhereRaw(
                        'LOWER(email) = ?',
                        [$identifier]
                    );
            })
            ->whereHas('role', function ($query) {
                $query
                    ->whereNull('school_id')
                    ->where('system_role', true)
                    ->where('active', true)
                    ->whereIn(
                        'role_name',
                        self::PLATFORM_ROLES
                    );
            })
            ->with('role')
            ->first();

        /*
         * All credential failures intentionally look identical.
         */
        if (
            ! $this->canAttempt($user)
            || ! Hash::check(
                $password,
                $user->password_hash
            )
        ) {
            if (
                $user
                && $this->basicAccountStateAllowsAttempt(
                    $user
                )
            ) {
                $this->recordFailedPasswordAttempt(
                    $user
                );
            }

            $this->audit->record(
                $request,
                'platform_authentication_password_failed',
                $user
            );

            throw new RuntimeException(
                'Authentication failed.'
            );
        }

        /*
         * Temporary bootstrap credentials expire.
         */
        if (
            $user->temporary_password
            && (
                ! $user->temporary_password_expires_at
                || $user->temporary_password_expires_at->isPast()
            )
        ) {
            $this->audit->record(
                $request,
                'platform_authentication_temporary_password_expired',
                $user
            );

            throw new RuntimeException(
                'Authentication failed.'
            );
        }

        if (! filled($user->email)) {
            throw new RuntimeException(
                'Authentication failed.'
            );
        }

        /*
         * Independent platform identity boundary validation.
         */
        $this->authContext->assertAccessible(
            $user
        );

        $otp = (string) random_int(
            100000,
            999999
        );

        /*
         * This token identifies the exact authentication attempt.
         * Only its HMAC is stored.
         */
        $challengeToken = Str::random(64);

        $challenge = DB::transaction(
            function () use (
                $user,
                $otp,
                $challengeToken,
                $request
            ) {
                /*
                 * Only one current login / activation attempt.
                 */
                AuthenticationChallenge::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->whereIn(
                        'purpose',
                        [
                            'login',
                            'first_login',
                            'activation',
                        ]
                    )
                    ->whereNull(
                        'consumed_at'
                    )
                    ->update([
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return AuthenticationChallenge::create([
                    'id' => (string) Str::uuid(),

                    'user_id' => $user->id,

                    /*
                     * Platform identities are always school-less.
                     */
                    'school_id' => null,

                    /*
                     * Never persist the OTP itself.
                     */
                    'otp_hash' => Hash::make(
                        $otp
                    ),

                    'purpose' => (
                        $user->first_login
                        || $user->temporary_password
                        || $user->force_password_reset_at
                    )
                        ? 'first_login'
                        : 'login',

                    'failed_attempts' => 0,

                    'resend_count' => 0,

                    'last_sent_at' => now(),

                    'expires_at' => now()
                        ->addMinutes(
                            self::OTP_TTL_MINUTES
                        ),

                    'consumed_at' => null,

                    'challenge_nonce_hash' => $this->hmac(
                        $challengeToken
                    ),

                    'ip_hash' => $this->nullableHmac(
                        $request->ip()
                    ),

                    'user_agent_hash' => $this->nullableHmac(
                        $request->userAgent()
                    ),
                ]);
            },
            3
        );

        try {
            Mail::to(
                $user->email
            )->send(
                new PlatformLoginOtpMail(
                    $otp,
                    self::OTP_TTL_MINUTES
                )
            );
        } catch (Throwable) {
            /*
             * Failed delivery must invalidate the challenge.
             */
            $challenge->forceFill([
                'consumed_at' => now(),
            ])->save();

            $this->audit->record(
                $request,
                'platform_authentication_otp_delivery_failed',
                $user
            );

            throw new RuntimeException(
                'Authentication failed.'
            );
        }

        /*
         * Password was valid, but authentication is NOT complete.
         */
        $user->forceFill([
            'failed_login_attempts' => 0,
            'account_locked_until' => null,
        ])->save();

        $this->audit->record(
            $request,
            'platform_authentication_otp_challenge_created',
            $user
        );

        return [
            'challenge_id' => (string) $challenge->id,

            'challenge_token' => $challengeToken,

            'purpose' => $challenge->purpose,

            'expires_in' => self::OTP_TTL_MINUTES * 60,

            'destination' => $this->maskEmail(
                (string) $user->email
            ),
        ];
    }

    public function verifyOtp(
        string $challengeId,
        string $challengeToken,
        string $otp,
        Request $request
    ): array {
        $result = DB::transaction(
            function () use (
                $challengeId,
                $challengeToken,
                $otp,
                $request
            ) {
                $challenge = AuthenticationChallenge::query()
                    ->whereKey($challengeId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $challenge
                    || $challenge->school_id !== null
                    || ! in_array(
                        $challenge->purpose,
                        [
                            'login',
                            'first_login',
                        ],
                        true
                    )
                    || $challenge->isConsumed()
                    || $challenge->isExpired()
                    || $challenge->failed_attempts
                        >= self::MAX_OTP_ATTEMPTS
                    || ! hash_equals(
                        $challenge->challenge_nonce_hash,
                        $this->hmac($challengeToken)
                    )
                    || ! $this->requestMatchesChallenge(
                        $challenge,
                        $request
                    )
                ) {
                    return [
                        'type' => 'failed',
                        'user' => null,
                    ];
                }

                $user = User::query()
                    ->with('role')
                    ->whereKey($challenge->user_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $this->canAttempt($user)
                    || $user->school_id !== null
                ) {
                    return [
                        'type' => 'failed',
                        'user' => $user,
                    ];
                }

                try {
                    $this->authContext->assertAccessible(
                        $user
                    );
                } catch (Throwable) {
                    return [
                        'type' => 'failed',
                        'user' => $user,
                    ];
                }

                /*
                 * IMPORTANT:
                 *
                 * A wrong OTP must persist its failed-attempt counter.
                 * Do not throw inside this transaction after saving,
                 * otherwise Laravel rolls the increment back.
                 */
                if (
                    ! Hash::check(
                        $otp,
                        $challenge->otp_hash
                    )
                ) {
                    $attempts = (
                        (int) $challenge->failed_attempts
                    ) + 1;

                    $challenge->forceFill([
                        'failed_attempts' => $attempts,

                        /*
                         * Permanently invalidate the challenge after
                         * the configured maximum number of failures.
                         */
                        'consumed_at' => $attempts
                            >= self::MAX_OTP_ATTEMPTS
                                ? now()
                                : null,
                    ])->save();

                    return [
                        'type' => 'failed_otp',
                        'user' => $user,
                    ];
                }

                /*
                 * Successful OTP verifies possession of the
                 * configured platform email address.
                 */
                if (! $user->email_verified_at) {
                    $user->forceFill([
                        'email_verified_at' => now(),
                    ])->save();
                }

                /*
                 * First login does NOT receive a JWT.
                 *
                 * Rotate this exact challenge into an activation
                 * credential. The original OTP and login challenge
                 * token immediately become unusable.
                 */
                if (
                    $challenge->purpose === 'first_login'
                    || $user->first_login
                    || $user->temporary_password
                    || $user->force_password_reset_at
                ) {
                    $activationToken = Str::random(64);

                    $challenge->forceFill([
                        'purpose' => 'activation',

                        /*
                         * Destroy usefulness of the previous OTP hash.
                         */
                        'otp_hash' => Hash::make(
                            Str::random(64)
                        ),

                        'challenge_nonce_hash' => $this->hmac(
                            $activationToken
                        ),

                        'failed_attempts' => 0,

                        'expires_at' => now()
                            ->addMinutes(
                                self::ACTIVATION_TTL_MINUTES
                            ),

                        'consumed_at' => null,
                    ])->save();

                    return [
                        'type' => 'activation',
                        'user' => $user,
                        'challenge' => $challenge,
                        'activation_token' => $activationToken,
                    ];
                }

                /*
                 * Returning activated Platform Owner:
                 * successful OTP completes authentication.
                 */
                $challenge->forceFill([
                    'consumed_at' => now(),
                ])->save();

                return [
                    'type' => 'session',
                    'user' => $user,
                    'challenge' => $challenge,
                ];
            },
            3
        );

        /*
         * Throw only AFTER the transaction has committed.
         *
         * This preserves failed_attempts while keeping the response
         * generic to prevent OTP/account information disclosure.
         */
        if (
            in_array(
                $result['type'],
                [
                    'failed',
                    'failed_otp',
                ],
                true
            )
        ) {
            if (
                isset($result['user'])
                && $result['user'] instanceof User
            ) {
                $this->audit->record(
                    $request,
                    'platform_authentication_otp_failed',
                    $result['user']
                );
            }

            throw new RuntimeException(
                'Verification failed.'
            );
        }

        /** @var User $user */
        $user = $result['user'];

        if ($result['type'] === 'activation') {
            $this->audit->record(
                $request,
                'platform_authentication_otp_verified_activation_required',
                $user
            );

            return [
                'authenticated' => false,
                'activation_required' => true,
                'challenge_id' => (string) $result['challenge']->id,
                'activation_token' => $result['activation_token'],
                'expires_in' => self::ACTIVATION_TTL_MINUTES * 60,
            ];
        }

        $session = $this->issueSession(
            $user,
            $request
        );

        $this->audit->record(
            $request,
            'platform_authentication_login_succeeded',
            $user
        );

        return $session;
    }

    public function activateFirstLogin(
        string $challengeId,
        string $activationToken,
        string $newPassword,
        Request $request
    ): array {
        $result = DB::transaction(
            function () use (
                $challengeId,
                $activationToken,
                $newPassword,
                $request
            ) {
                $challenge = AuthenticationChallenge::query()
                    ->whereKey(
                        $challengeId
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $challenge
                    || $challenge->school_id !== null
                    || $challenge->purpose !== 'activation'
                    || $challenge->isConsumed()
                    || $challenge->isExpired()
                    || ! hash_equals(
                        $challenge->challenge_nonce_hash,
                        $this->hmac(
                            $activationToken
                        )
                    )
                    || ! $this->requestMatchesChallenge(
                        $challenge,
                        $request
                    )
                ) {
                    throw new RuntimeException(
                        'Activation failed.'
                    );
                }

                $user = User::query()
                    ->with('role')
                    ->whereKey(
                        $challenge->user_id
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $this->canAttempt($user)
                    || $user->school_id !== null
                ) {
                    throw new RuntimeException(
                        'Activation failed.'
                    );
                }

                $this->authContext->assertAccessible(
                    $user
                );

                /*
                 * Activation is only valid while reset state exists.
                 */
                if (
                    ! $user->first_login
                    && ! $user->temporary_password
                    && ! $user->force_password_reset_at
                ) {
                    throw new RuntimeException(
                        'Activation failed.'
                    );
                }

                $this->validateNewPassword(
                    $user,
                    $newPassword
                );

                /*
                 * First-login activation is a credential rotation.
                 *
                 * Increment auth_generation to invalidate anything
                 * issued under the previous credential generation.
                 */
                $user->forceFill([
                    'password_hash' => Hash::make(
                        $newPassword
                    ),

                    'first_login' => false,

                    'temporary_password' => false,

                    'temporary_password_expires_at' => null,

                    'force_password_reset_at' => null,

                    'password_changed_at' => now(),

                    'activated_at' => now(),

                    'email_verified_at' => (
                        $user->email_verified_at
                        ?: now()
                    ),

                    /*
                     * Platform authentication mandates OTP.
                     */
                    'mfa_enabled' => true,

                    'failed_login_attempts' => 0,

                    'account_locked_until' => null,

                    'auth_generation' => max(
                        1,
                        (int) $user->auth_generation
                    ) + 1,
                ])->save();

                /*
                 * Activation token is one-time use.
                 */
                $challenge->forceFill([
                    'consumed_at' => now(),
                ])->save();

                /*
                 * Kill every other unfinished auth challenge.
                 */
                AuthenticationChallenge::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'id',
                        '!=',
                        $challenge->id
                    )
                    ->whereNull(
                        'consumed_at'
                    )
                    ->update([
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                /*
                 * Any old tracked sessions are revoked before the
                 * new credential generation becomes active.
                 */
                AuthenticationSession::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->whereNull(
                        'revoked_at'
                    )
                    ->update([
                        'revoked_at' => now(),

                        'revocation_reason' => 'first_login_credential_rotation',

                        'updated_at' => now(),
                    ]);

                return $user->fresh([
                    'role',
                ]);
            },
            3
        );

        $session = $this->issueSession(
            $result,
            $request
        );

        $this->audit->record(
            $request,
            'platform_owner_first_login_activated',
            $result
        );

        return $session;
    }

    private function issueSession(
        User $user,
        Request $request
    ): array {
        /*
         * Reload so JWT claims use the latest auth_generation.
         */
        $user = User::query()
            ->with('role')
            ->findOrFail(
                $user->id
            );

        $this->authContext->assertAccessible(
            $user
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $jti = $this->jwtIdentifier(
            $token
        );

        $deviceFingerprint = trim(
            (string) $request->header(
                'X-Device-Fingerprint'
            )
        );

        $authenticationSession = AuthenticationSession::create([
            'id' => (string) Str::uuid(),

            'user_id' => $user->id,

            'school_id' => null,

            'token_jti_hash' => $jti
                ? $this->hmac(
                    $jti
                )
                : null,

            'device_fingerprint_hash' => $deviceFingerprint !== ''
                    ? $this->hmac(
                        $deviceFingerprint
                    )
                    : null,

            'user_agent_summary' => Str::limit(
                (string) $request->userAgent(),
                255,
                ''
            ),

            'ip_hash' => $this->nullableHmac(
                $request->ip()
            ),

            'authenticated_at' => now(),

            'last_seen_at' => now(),

            'revoked_at' => null,

            'revocation_reason' => null,
        ]);

        $user->forceFill([
            'last_login' => now(),
        ])->save();

        $context = $this->authContext->resolve(
            $user
        );

        return [
            'authenticated' => true,

            'activation_required' => false,

            'token' => $token,

            'token_type' => 'bearer',

            'expires_in' => (
                (int) config(
                    'jwt.ttl',
                    60
                )
            ) * 60,

            'session_id' => (string) $authenticationSession->id,

            'user' => $context,
        ];
    }

    private function validateNewPassword(
        User $user,
        string $newPassword
    ): void {
        $validator = Validator::make(
            [
                'password' => $newPassword,
            ],
            [
                'password' => [
                    'required',
                    'string',

                    Password::min(16)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ]
        );

        $validator->after(
            function ($validator) use (
                $user,
                $newPassword
            ) {
                /*
                 * Temporary password cannot become permanent.
                 */
                if (
                    Hash::check(
                        $newPassword,
                        $user->password_hash
                    )
                ) {
                    $validator->errors()->add(
                        'password',
                        'The new password must be different from the temporary password.'
                    );
                }

                $lowerPassword = Str::lower(
                    $newPassword
                );

                $username = Str::lower(
                    trim(
                        (string) $user->username
                    )
                );

                if (
                    $username !== ''
                    && str_contains(
                        $lowerPassword,
                        $username
                    )
                ) {
                    $validator->errors()->add(
                        'password',
                        'The password must not contain the username.'
                    );
                }

                $emailLocalPart = Str::lower(
                    Str::before(
                        (string) $user->email,
                        '@'
                    )
                );

                if (
                    strlen($emailLocalPart) >= 4
                    && str_contains(
                        $lowerPassword,
                        $emailLocalPart
                    )
                ) {
                    $validator->errors()->add(
                        'password',
                        'The password must not contain the email identifier.'
                    );
                }
            }
        );

        $validator->validate();
    }

    private function canAttempt(
        ?User $user
    ): bool {
        if (
            ! $user
            || ! $this->basicAccountStateAllowsAttempt(
                $user
            )
        ) {
            return false;
        }

        return in_array(
            $user->role?->role_name,
            self::PLATFORM_ROLES,
            true
        );
    }

    private function basicAccountStateAllowsAttempt(
        User $user
    ): bool {
        return (bool) (
            $user->active
            && ! $user->is_deleted
            && ! $user->suspended_at
            && ! $user->account_locked_until?->isFuture()
        );
    }

    private function recordFailedPasswordAttempt(
        User $user
    ): void {
        $attempts = (
            (int) $user->failed_login_attempts
        ) + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,

            'last_failed_login' => now(),

            /*
             * Temporary lock rather than attacker-triggerable
             * permanent lockout.
             */
            'account_locked_until' => $attempts >= 5
                ? now()->addMinutes(15)
                : null,
        ])->save();
    }

    private function requestMatchesChallenge(
        AuthenticationChallenge $challenge,
        Request $request
    ): bool {
        if (
            $challenge->ip_hash
            && ! hash_equals(
                $challenge->ip_hash,
                $this->hmac(
                    (string) $request->ip()
                )
            )
        ) {
            return false;
        }

        if (
            $challenge->user_agent_hash
            && ! hash_equals(
                $challenge->user_agent_hash,
                $this->hmac(
                    (string) $request->userAgent()
                )
            )
        ) {
            return false;
        }

        return true;
    }

    private function jwtIdentifier(
        string $token
    ): ?string {
        try {
            $payload = JWTAuth::setToken(
                $token
            )->getPayload();

            $jti = $payload->get(
                'jti'
            );

            return filled($jti)
                ? (string) $jti
                : null;
        } catch (Throwable) {
            /*
             * The session remains valid even if the JWT implementation
             * does not expose a jti. The schema intentionally allows null.
             */
            return null;
        }
    }

    private function hmac(
        string $value
    ): string {
        return hash_hmac(
            'sha256',
            $value,
            $this->hashKey()
        );
    }

    private function nullableHmac(
        ?string $value
    ): ?string {
        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $this->hmac(
                $value
            );
    }

    private function hashKey(): string
    {
        $key = (string) config(
            'app.key'
        );

        if ($key === '') {
            throw new RuntimeException(
                'Application security key is unavailable.'
            );
        }

        return $key;
    }

    private function maskEmail(
        string $email
    ): string {
        [$local, $domain] = array_pad(
            explode(
                '@',
                $email,
                2
            ),
            2,
            ''
        );

        if (
            $local === ''
            || $domain === ''
        ) {
            return '***';
        }

        $visible = mb_substr(
            $local,
            0,
            min(
                2,
                mb_strlen($local)
            )
        );

        return $visible
            .'***@'
            .$domain;
    }
}
