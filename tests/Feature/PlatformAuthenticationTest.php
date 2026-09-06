<?php

namespace Tests\Feature;

use App\Mail\PlatformLoginOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PlatformAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(
                random_bytes(32)
            ),

            'jwt.secret' => str_repeat(
                'platform-authentication-test-secret-',
                3
            ),

            'jwt.ttl' => 60,

            'jwt.blacklist_enabled' => true,
        ]);

        $this->platformRoleId = $this->systemRole(
            'Platform Owner'
        );

        $this->schoolRoleId = $this->systemRole(
            'School Admin'
        );
    }

    public function test_valid_platform_password_creates_otp_challenge_but_no_jwt(): void
    {
        [$user, $response] = $this->beginLogin();

        $response
            ->assertStatus(202)
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.authenticated',
                false
            )
            ->assertJsonPath(
                'data.purpose',
                'first_login'
            )
            ->assertJsonMissingPath(
                'token'
            )
            ->assertJsonMissingPath(
                'data.token'
            );

        $challenge = DB::table(
            'authentication_challenges'
        )
            ->where(
                'id',
                $response->json(
                    'data.challenge_id'
                )
            )
            ->first();

        $this->assertNotNull(
            $challenge
        );

        $this->assertNull(
            $challenge->school_id
        );

        $this->assertSame(
            $user->id,
            $challenge->user_id
        );
    }

    public function test_valid_first_login_otp_requires_activation_and_still_returns_no_jwt(): void
    {
        [$user, $login, $otp] = $this->beginLoginWithOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        );

        $verify
            ->assertStatus(202)
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.authenticated',
                false
            )
            ->assertJsonPath(
                'data.activation_required',
                true
            )
            ->assertJsonMissingPath(
                'token'
            )
            ->assertJsonMissingPath(
                'data.token'
            );

        $this->assertNotEmpty(
            $verify->json(
                'data.activation_token'
            )
        );

        $user->refresh();

        $this->assertNotNull(
            $user->email_verified_at
        );

        $this->assertTrue(
            $user->first_login
        );

        $challenge = DB::table(
            'authentication_challenges'
        )
            ->where(
                'id',
                $login->json(
                    'data.challenge_id'
                )
            )
            ->first();

        $this->assertSame(
            'activation',
            $challenge->purpose
        );

        $this->assertNull(
            $challenge->consumed_at
        );
    }

    public function test_wrong_otp_increments_failed_attempts(): void
    {
        [, $login] = $this->beginLoginWithOtp();

        $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => '000000',
            ]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Verification failed.'
            );

        $challenge = DB::table(
            'authentication_challenges'
        )
            ->where(
                'id',
                $login->json(
                    'data.challenge_id'
                )
            )
            ->first();

        $this->assertSame(
            1,
            (int) $challenge->failed_attempts
        );
    }

    public function test_original_otp_and_login_challenge_token_cannot_be_reused_after_verification(): void
    {
        [, $login, $otp] = $this->beginLoginWithOtp();

        $payload = [
            'challenge_id' => $login->json(
                'data.challenge_id'
            ),

            'challenge_token' => $login->json(
                'data.challenge_token'
            ),

            'otp' => $otp,
        ];

        $this->postJson(
            '/api/platform/auth/verify-otp',
            $payload
        )->assertStatus(202);

        $this->postJson(
            '/api/platform/auth/verify-otp',
            $payload
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Verification failed.'
            );
    }

    public function test_activation_changes_password_and_only_then_issues_jwt_and_session(): void
    {
        [$user, $login, $otp] = $this->beginLoginWithOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        )->assertStatus(202);

        $newPassword = 'NewSecure#Platform84';

        $activation = $this->postJson(
            '/api/platform/auth/activate',
            [
                'challenge_id' => $verify->json(
                    'data.challenge_id'
                ),

                'activation_token' => $verify->json(
                    'data.activation_token'
                ),

                'password' => $newPassword,

                'password_confirmation' => $newPassword,
            ]
        );

        $activation
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.authenticated',
                true
            )
            ->assertJsonPath(
                'data.activation_required',
                false
            )
            ->assertJsonPath(
                'data.user.scope',
                'platform'
            )
            ->assertJsonPath(
                'data.user.school_id',
                null
            );

        $this->assertNotEmpty(
            $activation->json(
                'data.token'
            )
        );

        $this->assertNotEmpty(
            $activation->json(
                'data.session_id'
            )
        );

        $user->refresh();

        $this->assertFalse(
            $user->first_login
        );

        $this->assertFalse(
            $user->temporary_password
        );

        $this->assertNull(
            $user->temporary_password_expires_at
        );

        $this->assertNull(
            $user->force_password_reset_at
        );

        $this->assertNotNull(
            $user->password_changed_at
        );

        $this->assertNotNull(
            $user->activated_at
        );

        $this->assertNotNull(
            $user->email_verified_at
        );

        $this->assertTrue(
            $user->mfa_enabled
        );

        $this->assertTrue(
            Hash::check(
                $newPassword,
                $user->password_hash
            )
        );

        $this->assertDatabaseHas(
            'authentication_sessions',
            [
                'id' => $activation->json(
                    'data.session_id'
                ),

                'user_id' => $user->id,

                'school_id' => null,

                'revoked_at' => null,
            ]
        );

        $challenge = DB::table(
            'authentication_challenges'
        )
            ->where(
                'id',
                $verify->json(
                    'data.challenge_id'
                )
            )
            ->first();

        $this->assertNotNull(
            $challenge->consumed_at
        );
    }

    public function test_activation_rejects_temporary_password_reuse(): void
    {
        [, $login, $otp] = $this->beginLoginWithOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        );

        $this->postJson(
            '/api/platform/auth/activate',
            [
                'challenge_id' => $verify->json(
                    'data.challenge_id'
                ),

                'activation_token' => $verify->json(
                    'data.activation_token'
                ),

                'password' => 'Correct#Platform99',

                'password_confirmation' => 'Correct#Platform99',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'success',
                false
            );
    }

    public function test_activation_rejects_weak_new_password(): void
    {
        [, $login, $otp] = $this->beginLoginWithOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        );

        $this->postJson(
            '/api/platform/auth/activate',
            [
                'challenge_id' => $verify->json(
                    'data.challenge_id'
                ),

                'activation_token' => $verify->json(
                    'data.activation_token'
                ),

                'password' => 'weak',

                'password_confirmation' => 'weak',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'success',
                false
            );
    }

    public function test_activation_token_cannot_be_replayed(): void
    {
        [, $login, $otp] = $this->beginLoginWithOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        );

        $payload = [
            'challenge_id' => $verify->json(
                'data.challenge_id'
            ),

            'activation_token' => $verify->json(
                'data.activation_token'
            ),

            'password' => 'AnotherSecure#Password47',

            'password_confirmation' => 'AnotherSecure#Password47',
        ];

        $this->postJson(
            '/api/platform/auth/activate',
            $payload
        )->assertOk();

        $this->postJson(
            '/api/platform/auth/activate',
            $payload
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Activation failed.'
            );
    }

    public function test_returning_activated_platform_owner_receives_jwt_only_after_otp(): void
    {
        Mail::fake();

        $user = $this->platformUser();

        $user->forceFill([
            'first_login' => false,

            'temporary_password' => false,

            'temporary_password_expires_at' => null,

            'force_password_reset_at' => null,

            'email_verified_at' => now(),

            'activated_at' => now(),

            'mfa_enabled' => true,
        ])->save();

        $login = $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => $user->email,

                'password' => 'Correct#Platform99',
            ]
        );

        $login
            ->assertStatus(202)
            ->assertJsonPath(
                'data.purpose',
                'login'
            )
            ->assertJsonMissingPath(
                'token'
            );

        $otp = $this->sentOtp();

        $verify = $this->postJson(
            '/api/platform/auth/verify-otp',
            [
                'challenge_id' => $login->json(
                    'data.challenge_id'
                ),

                'challenge_token' => $login->json(
                    'data.challenge_token'
                ),

                'otp' => $otp,
            ]
        );

        $verify
            ->assertOk()
            ->assertJsonPath(
                'data.authenticated',
                true
            )
            ->assertJsonPath(
                'data.activation_required',
                false
            );

        $this->assertNotEmpty(
            $verify->json(
                'data.token'
            )
        );
    }

    public function test_unknown_platform_identifier_still_performs_password_verification(): void
    {
        Mail::fake();

        Hash::shouldReceive('check')
            ->once()
            ->withArgs(
                function (
                    string $password,
                    string $hash
                ): bool {
                    return $password === 'Incorrect#Platform99'
                        && $hash !== '';
                }
            )
            ->andReturn(false);

        $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => 'missing-platform-owner@example.test',
                'password' => 'Incorrect#Platform99',
            ]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Authentication failed.'
            );

        Mail::assertNothingSent();
    }

    public function test_school_user_cannot_use_platform_login(): void
    {
        Mail::fake();

        $schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,

            'school_name' => 'Tenant School',

            'school_code' => 'TENANT-1',

            'active' => true,

            'is_deleted' => false,

            'lifecycle_state' => 'active',

            'created_at' => now(),

            'updated_at' => now(),
        ]);

        User::create([
            'id' => (string) Str::uuid(),

            'school_id' => $schoolId,

            'role_id' => $this->schoolRoleId,

            'username' => 'school.admin',

            'password_hash' => Hash::make(
                'Correct#Platform99'
            ),

            'email' => 'school-admin@example.test',

            'first_name' => 'School',

            'last_name' => 'Admin',

            'active' => true,

            'first_login' => false,

            'auth_generation' => 1,

            'is_deleted' => false,
        ]);

        $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => 'school-admin@example.test',

                'password' => 'Correct#Platform99',
            ]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Authentication failed.'
            );

        Mail::assertNothingSent();
    }

    public function test_unknown_account_and_wrong_password_return_same_response(): void
    {
        Mail::fake();

        $user = $this->platformUser();

        $unknown = $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => 'unknown@example.test',

                'password' => 'Wrong#Password99',
            ]
        );

        $wrong = $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => $user->email,

                'password' => 'Wrong#Password99',
            ]
        );

        $unknown
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,

                'message' => 'Authentication failed.',
            ]);

        $wrong
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,

                'message' => 'Authentication failed.',
            ]);

        Mail::assertNothingSent();
    }

    public function test_expired_temporary_password_is_rejected(): void
    {
        Mail::fake();

        $user = $this->platformUser();

        $user->forceFill([
            'temporary_password_expires_at' => now()->subMinute(),
        ])->save();

        $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => $user->email,

                'password' => 'Correct#Platform99',
            ]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'Authentication failed.'
            );

        Mail::assertNothingSent();
    }

    public function test_platform_login_response_never_contains_otp_plaintext(): void
    {
        Mail::fake();

        $user = $this->platformUser();

        $response = $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => $user->username,

                'password' => 'Correct#Platform99',
            ]
        );

        $response->assertStatus(202);

        $payload = json_encode(
            $response->json(),
            JSON_THROW_ON_ERROR
        );

        Mail::assertSent(
            PlatformLoginOtpMail::class,
            function (
                PlatformLoginOtpMail $mail
            ) use ($payload) {
                $this->assertStringNotContainsString(
                    $mail->otp,
                    $payload
                );

                return true;
            }
        );
    }

    private function beginLogin(): array
    {
        Mail::fake();

        $user = $this->platformUser();

        $response = $this->postJson(
            '/api/platform/auth/login',
            [
                'identifier' => $user->email,

                'password' => 'Correct#Platform99',
            ]
        );

        return [
            $user,
            $response,
        ];
    }

    private function beginLoginWithOtp(): array
    {
        [$user, $response] = $this->beginLogin();

        $response->assertStatus(202);

        return [
            $user,
            $response,
            $this->sentOtp(),
        ];
    }

    private function sentOtp(): string
    {
        $otp = null;

        Mail::assertSent(
            PlatformLoginOtpMail::class,
            function (
                PlatformLoginOtpMail $mail
            ) use (&$otp) {
                $otp = $mail->otp;

                return true;
            }
        );

        if (! $otp) {
            throw new RuntimeException(
                'Expected OTP mail was not sent.'
            );
        }

        return $otp;
    }

    private function platformUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),

            'school_id' => null,

            'role_id' => $this->platformRoleId,

            'username' => 'platform.owner.'
                .Str::lower(
                    Str::random(6)
                ),

            'password_hash' => Hash::make(
                'Correct#Platform99'
            ),

            'email' => 'platform-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',

            'first_name' => 'Platform',

            'last_name' => 'Owner',

            'active' => true,

            'first_login' => true,

            'temporary_password' => true,

            'temporary_password_expires_at' => now()->addHour(),

            'force_password_reset_at' => now(),

            'auth_generation' => 1,

            'is_deleted' => false,
        ]);
    }

    private function systemRole(
        string $name
    ): string {
        $roleId = DB::table('roles')
            ->where(
                'role_name',
                $name
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

        if (! $roleId) {
            throw new RuntimeException(
                "Required system role [{$name}] was not found."
            );
        }

        return (string) $roleId;
    }
}
