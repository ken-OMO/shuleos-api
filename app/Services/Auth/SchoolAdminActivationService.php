<?php

namespace App\Services\Auth;

use App\Mail\SchoolAdminActivationOtpMail;
use App\Models\AuthenticationChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SchoolAdminActivationService
{
    private const CHALLENGE_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    public function begin(
        User $user,
        Request $request
    ): array {
        AuthenticationChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'school_id',
                $user->school_id
            )
            ->whereIn(
                'purpose',
                [
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

        if (! filled($user->email)) {
            throw new \RuntimeException(
                'Activation failed.'
            );
        }

        $challengeToken = Str::random(64);

        $otp = (string) random_int(
            100000,
            999999
        );

        $challenge = AuthenticationChallenge::create([
            'id' => (string) Str::uuid(),

            'user_id' => $user->id,

            'school_id' => $user->school_id,

            /*
             * OTP verification is introduced in the next sub-phase.
             * Keep a non-secret unusable placeholder hash for now so the
             * challenge record matches the existing schema contract.
             */
            'otp_hash' => Hash::make(
                $otp
            ),

            'purpose' => 'first_login',

            'failed_attempts' => 0,

            'resend_count' => 0,

            'last_sent_at' => now(),

            'expires_at' => now()->addMinutes(
                self::CHALLENGE_TTL_MINUTES
            ),

            'consumed_at' => null,

            'challenge_nonce_hash' => $this->hmac(
                $challengeToken
            ),

            'ip_hash' => $this->hmac(
                (string) $request->ip()
            ),

            'user_agent_hash' => $this->hmac(
                (string) $request->userAgent()
            ),
        ]);

        try {
            Mail::to(
                $user->email
            )->send(
                new SchoolAdminActivationOtpMail(
                    $otp,
                    self::CHALLENGE_TTL_MINUTES
                )
            );
        } catch (\Throwable $exception) {
            /*
             * Fail closed: an undelivered challenge must not remain usable.
             */
            $challenge->forceFill([
                'consumed_at' => now(),
                'updated_at' => now(),
            ])->save();

            throw new \RuntimeException(
                'Activation failed.',
                previous: $exception
            );
        }

        return [
            'authenticated' => false,

            'activation_required' => true,

            'challenge_id' => (string) $challenge->id,

            'challenge_token' => $challengeToken,

            'purpose' => 'first_login',

            'expires_in' => self::CHALLENGE_TTL_MINUTES * 60,
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

                /*
                 * Challenge identity, tenancy, lifecycle and nonce must
                 * validate before any OTP attempt counter is mutated.
                 */
                if (
                    ! $challenge
                    || $challenge->school_id === null
                    || $challenge->purpose !== 'first_login'
                    || $challenge->isConsumed()
                    || $challenge->isExpired()
                    || ! hash_equals(
                        $challenge->challenge_nonce_hash,
                        $this->hmac($challengeToken)
                    )
                ) {
                    throw new \RuntimeException(
                        'Verification failed.'
                    );
                }

                if (
                    $challenge->ip_hash
                    && ! hash_equals(
                        $challenge->ip_hash,
                        $this->hmac(
                            (string) $request->ip()
                        )
                    )
                ) {
                    throw new \RuntimeException(
                        'Verification failed.'
                    );
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
                    throw new \RuntimeException(
                        'Verification failed.'
                    );
                }

                $user = User::query()
                    ->whereKey(
                        $challenge->user_id
                    )
                    ->where(
                        'school_id',
                        $challenge->school_id
                    )
                    ->first();

                if (
                    ! $user
                    || ! $user->active
                    || $user->is_deleted
                    || ! $user->requiresPasswordReset()
                ) {
                    throw new \RuntimeException(
                        'Verification failed.'
                    );
                }

                /*
                 * Only a caller possessing the valid challenge nonce can
                 * advance the OTP failure counter.
                 */
                if (
                    ! Hash::check(
                        $otp,
                        $challenge->otp_hash
                    )
                ) {
                    $failedAttempts = (
                        (int) $challenge->failed_attempts
                    ) + 1;

                    $challenge->forceFill([
                        'failed_attempts' => $failedAttempts,

                        /*
                         * Exhaust the challenge after the bounded number
                         * of OTP attempts.
                         */
                        'consumed_at' => (
                            $failedAttempts >= self::OTP_MAX_ATTEMPTS
                                ? now()
                                : null
                        ),

                        'updated_at' => now(),
                    ])->save();

                    /*
                     * Return instead of throwing here so the transaction
                     * commits the failed-attempt counter.
                     */
                    return null;
                }

                $activationToken = Str::random(64);

                $challenge->forceFill([
                    'purpose' => 'activation',

                    /*
                     * Destroy usefulness of the verified OTP.
                     */
                    'otp_hash' => Hash::make(
                        Str::random(64)
                    ),

                    'challenge_nonce_hash' => $this->hmac(
                        $activationToken
                    ),

                    'failed_attempts' => 0,

                    'expires_at' => now()->addMinutes(
                        self::CHALLENGE_TTL_MINUTES
                    ),

                    'consumed_at' => null,

                    'updated_at' => now(),
                ])->save();

                return [
                    'authenticated' => false,

                    'activation_required' => true,

                    'challenge_id' => (string) $challenge->id,

                    'activation_token' => $activationToken,

                    'expires_in' => self::CHALLENGE_TTL_MINUTES * 60,
                ];
            }
        );

        if ($result === null) {
            throw new \RuntimeException(
                'Verification failed.'
            );
        }

        return $result;
    }

    public function activate(
        string $challengeId,
        string $activationToken,
        string $newPassword,
        Request $request
    ): User {
        return DB::transaction(
            function () use (
                $challengeId,
                $activationToken,
                $newPassword,
                $request
            ) {
                $challenge = AuthenticationChallenge::query()
                    ->whereKey($challengeId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $challenge
                    || $challenge->school_id === null
                    || $challenge->purpose !== 'activation'
                    || $challenge->isConsumed()
                    || $challenge->isExpired()
                    || ! hash_equals(
                        $challenge->challenge_nonce_hash,
                        $this->hmac(
                            $activationToken
                        )
                    )
                ) {
                    throw new \RuntimeException(
                        'Activation failed.'
                    );
                }

                if (
                    $challenge->ip_hash
                    && ! hash_equals(
                        $challenge->ip_hash,
                        $this->hmac(
                            (string) $request->ip()
                        )
                    )
                ) {
                    throw new \RuntimeException(
                        'Activation failed.'
                    );
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
                    throw new \RuntimeException(
                        'Activation failed.'
                    );
                }

                $user = User::query()
                    ->whereKey(
                        $challenge->user_id
                    )
                    ->where(
                        'school_id',
                        $challenge->school_id
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $user
                    || ! $user->active
                    || $user->is_deleted
                    || ! $user->requiresPasswordReset()
                ) {
                    throw new \RuntimeException(
                        'Activation failed.'
                    );
                }

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

                if ($validator->fails()) {
                    throw ValidationException::withMessages(
                        $validator->errors()->toArray()
                    );
                }
                /*
                 * A permanent credential must not equal the temporary
                 * credential currently stored for this account.
                 */
                if (
                    Hash::check(
                        $newPassword,
                        $user->password_hash
                    )
                ) {
                    throw ValidationException::withMessages([
                        'password' => [
                            'The new password must be different from the temporary password.',
                        ],
                    ]);
                }

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

                    'failed_login_attempts' => 0,

                    'account_locked_until' => null,

                    'auth_generation' => (
                        (int) $user->auth_generation
                    ) + 1,
                ])->save();

                /*
                 * Activation credential is strictly one-time.
                 */
                $challenge->forceFill([
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ])->save();

                /*
                 * Consume any other unfinished first-login or activation
                 * challenge belonging to this same tenant identity.
                 */
                AuthenticationChallenge::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'school_id',
                        $user->school_id
                    )
                    ->where(
                        'id',
                        '!=',
                        $challenge->id
                    )
                    ->whereIn(
                        'purpose',
                        [
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

                return $user->fresh();
            }
        );
    }

    private function hmac(
        string $value
    ): string {
        return hash_hmac(
            'sha256',
            $value,
            (string) config(
                'app.key'
            )
        );
    }
}
