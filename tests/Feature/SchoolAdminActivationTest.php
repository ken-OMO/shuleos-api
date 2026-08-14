<?php

namespace Tests\Feature;

use App\Mail\SchoolAdminActivationOtpMail;
use App\Models\AuthenticationChallenge;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolAdminActivationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_with_temporary_password_does_not_receive_jwt_before_activation(): void
    {
        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Activation Test School',
            'school_code' => 'ATS'.Str::upper(Str::random(8)),
            'login_prefix' => 'ATS',
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'lifecycle_version' => 1,
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
        ]);

        $role = Role::query()
            ->where('role_name', 'School Admin')
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->firstOrFail();

        $password = 'TemporaryPass123!';

        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'username' => 'school.admin.'.Str::lower(Str::random(6)),
            'password_hash' => Hash::make($password),
            'email' => 'school-admin-'.Str::lower(Str::random(6)).'@example.test',
            'first_name' => 'School',
            'last_name' => 'Administrator',
            'active' => true,
            'is_deleted' => false,
            'first_login' => true,
            'temporary_password' => true,
            'temporary_password_expires_at' => now()->addHours(24),
            'force_password_reset_at' => now(),
            'activated_at' => null,
            'auth_generation' => 1,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => $password,
        ]);

        /*
         * RED contract:
         *
         * A first-login School Admin must not receive a normal JWT merely
         * because the temporary credential is correct.
         *
         * The eventual implementation will return an activation challenge.
         */
        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.authenticated', false)
            ->assertJsonPath('data.activation_required', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_first_login_school_admin_receives_school_bound_activation_challenge(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'TemporaryChallenge123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $response = $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $password,
            ]
        );

        $response
            ->assertStatus(202)
            ->assertJsonPath(
                'data.authenticated',
                false
            )
            ->assertJsonPath(
                'data.activation_required',
                true
            )
            ->assertJsonPath(
                'data.purpose',
                'first_login'
            )
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'challenge_token',
                    'purpose',
                    'authenticated',
                    'activation_required',
                    'expires_in',
                ],
            ])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');

        $challengeId = $response->json(
            'data.challenge_id'
        );

        $challenge = DB::table('authentication_challenges')
            ->where(
                'id',
                $challengeId
            )
            ->first();

        $this->assertNotNull(
            $challenge
        );

        $this->assertSame(
            $user->id,
            $challenge->user_id
        );

        $this->assertSame(
            $school->id,
            $challenge->school_id
        );

        $this->assertSame(
            'first_login',
            $challenge->purpose
        );

        $this->assertNull(
            $challenge->consumed_at
        );
    }

    public function test_valid_first_login_otp_rotates_challenge_into_activation_credential(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'TemporaryOtp123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $otp = '482731';
        $challengeToken = Str::random(64);

        $challenge = AuthenticationChallenge::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'school_id' => $school->id,
            'otp_hash' => Hash::make($otp),
            'purpose' => 'first_login',
            'failed_attempts' => 0,
            'resend_count' => 0,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'challenge_nonce_hash' => hash_hmac(
                'sha256',
                $challengeToken,
                (string) config('app.key')
            ),
            'ip_hash' => hash_hmac(
                'sha256',
                '127.0.0.1',
                (string) config('app.key')
            ),
            'user_agent_hash' => null,
        ]);

        $response = $this->postJson(
            '/api/auth/first-login/verify-otp',
            [
                'challenge_id' => $challenge->id,
                'challenge_token' => $challengeToken,
                'otp' => $otp,
            ]
        );

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
                'data.activation_required',
                true
            )
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'activation_token',
                    'expires_in',
                    'authenticated',
                    'activation_required',
                ],
            ])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');

        $fresh = $challenge->fresh();

        $this->assertSame(
            'activation',
            $fresh->purpose
        );

        $this->assertNull(
            $fresh->consumed_at
        );

        $this->assertFalse(
            Hash::check(
                $otp,
                $fresh->otp_hash
            )
        );
    }

    public function test_valid_activation_token_rotates_password_and_issues_jwt(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryActivate123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        $activationToken = Str::random(64);

        $challenge = AuthenticationChallenge::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'school_id' => $school->id,
            'otp_hash' => Hash::make(
                Str::random(64)
            ),
            'purpose' => 'activation',
            'failed_attempts' => 0,
            'resend_count' => 0,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'challenge_nonce_hash' => hash_hmac(
                'sha256',
                $activationToken,
                (string) config('app.key')
            ),
            'ip_hash' => hash_hmac(
                'sha256',
                '127.0.0.1',
                (string) config('app.key')
            ),
            'user_agent_hash' => null,
        ]);

        $newPassword = 'PermanentSchoolAdmin123!';

        $response = $this->postJson(
            '/api/auth/first-login/activate',
            [
                'challenge_id' => $challenge->id,
                'activation_token' => $activationToken,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]
        );

        $response
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
            ->assertJsonStructure([
                'token',
                'data' => [
                    'token',
                    'token_type',
                    'expires_in',
                    'authenticated',
                    'activation_required',
                ],
            ]);

        $freshUser = $user->fresh();

        $this->assertTrue(
            Hash::check(
                $newPassword,
                $freshUser->password_hash
            )
        );

        $this->assertFalse(
            Hash::check(
                $temporaryPassword,
                $freshUser->password_hash
            )
        );

        $this->assertFalse(
            (bool) $freshUser->first_login
        );

        $this->assertFalse(
            (bool) $freshUser->temporary_password
        );

        $this->assertNull(
            $freshUser->temporary_password_expires_at
        );

        $this->assertNull(
            $freshUser->force_password_reset_at
        );

        $this->assertNotNull(
            $freshUser->activated_at
        );

        $this->assertNotNull(
            $freshUser->email_verified_at
        );

        $this->assertNotNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_activation_token_cannot_be_replayed(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryReplay123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$challenge, $activationToken] = $this->activationChallenge(
            $user,
            $school
        );

        $payload = [
            'challenge_id' => $challenge->id,
            'activation_token' => $activationToken,
            'password' => 'PermanentReplay123!',
            'password_confirmation' => 'PermanentReplay123!',
        ];

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                $payload
            )
            ->assertOk();

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                $payload
            )
            ->assertUnauthorized();

        $this->assertNotNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_expired_activation_credential_is_rejected(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryExpiredActivation123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$challenge, $activationToken] = $this->activationChallenge(
            $user,
            $school,
            [
                'expires_at' => now()->subMinute(),
            ]
        );

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $challenge->id,
                    'activation_token' => $activationToken,
                    'password' => 'PermanentExpired123!',
                    'password_confirmation' => 'PermanentExpired123!',
                ]
            )
            ->assertUnauthorized();

        $fresh = $user->fresh();

        $this->assertTrue(
            (bool) $fresh->first_login
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $fresh->password_hash
            )
        );

        $this->assertNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_wrong_activation_token_is_rejected_without_consuming_challenge(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role,
            'TemporaryWrongToken123!'
        );

        [$challenge] = $this->activationChallenge(
            $user,
            $school
        );

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $challenge->id,
                    'activation_token' => Str::random(64),
                    'password' => 'PermanentWrongToken123!',
                    'password_confirmation' => 'PermanentWrongToken123!',
                ]
            )
            ->assertUnauthorized();

        $this->assertNull(
            $challenge->fresh()->consumed_at
        );

        $this->assertTrue(
            (bool) $user->fresh()->first_login
        );
    }

    public function test_activation_challenge_cannot_be_substituted_across_schools(): void
    {
        [$school, $role] = $this->schoolFixture();

        [$otherSchool] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryCrossSchool123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$challenge, $activationToken] = $this->activationChallenge(
            $user,
            $otherSchool
        );

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $challenge->id,
                    'activation_token' => $activationToken,
                    'password' => 'PermanentCrossSchool123!',
                    'password_confirmation' => 'PermanentCrossSchool123!',
                ]
            )
            ->assertUnauthorized();

        $fresh = $user->fresh();

        $this->assertTrue(
            (bool) $fresh->first_login
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $fresh->password_hash
            )
        );

        $this->assertNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_temporary_password_cannot_be_reused_as_permanent_password(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryReuse123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$challenge, $activationToken] = $this->activationChallenge(
            $user,
            $school
        );

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $challenge->id,
                    'activation_token' => $activationToken,
                    'password' => $temporaryPassword,
                    'password_confirmation' => $temporaryPassword,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
            ]);

        $fresh = $user->fresh();

        $this->assertTrue(
            (bool) $fresh->first_login
        );

        $this->assertTrue(
            (bool) $fresh->temporary_password
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $fresh->password_hash
            )
        );

        $this->assertNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_first_login_sends_activation_otp_without_exposing_secret(): void
    {
        Mail::fake();

        [$school, $role] = $this->schoolFixture();

        $password = 'TemporaryMail123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $response = $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $password,
            ]
        );

        $response
            ->assertStatus(202)
            ->assertJsonMissingPath('otp')
            ->assertJsonMissingPath('data.otp');

        Mail::assertSent(
            SchoolAdminActivationOtpMail::class,
            function ($mail) use ($user) {
                return $mail->hasTo(
                    $user->email
                );
            }
        );

        Mail::assertSentCount(1);
    }

    public function test_activation_otp_is_hashed_at_rest_and_never_exposed_by_api(): void
    {
        Mail::fake();

        [$school, $role] = $this->schoolFixture();

        $password = 'TemporarySecretHandling123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $response = $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $password,
            ]
        );

        $response->assertStatus(202);

        $challengeId = $response->json(
            'data.challenge_id'
        );

        $challenge = AuthenticationChallenge::query()
            ->whereKey(
                $challengeId
            )
            ->firstOrFail();

        $capturedOtp = null;

        Mail::assertSent(
            SchoolAdminActivationOtpMail::class,
            function ($mail) use (
                $user,
                &$capturedOtp
            ) {
                $capturedOtp = $mail->otp;

                return $mail->hasTo(
                    $user->email
                );
            }
        );

        $this->assertNotNull(
            $capturedOtp
        );

        $this->assertMatchesRegularExpression(
            '/^\d{6}$/',
            $capturedOtp
        );

        $this->assertTrue(
            Hash::check(
                $capturedOtp,
                $challenge->otp_hash
            )
        );

        $this->assertNotSame(
            $capturedOtp,
            $challenge->otp_hash
        );

        $payload = $response->getContent();

        $this->assertStringNotContainsString(
            $capturedOtp,
            $payload
        );

        $stored = json_encode(
            $challenge->getAttributes(),
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            $capturedOtp,
            $stored
        );
    }

    public function test_wrong_otp_increments_failed_attempts_and_exhausts_challenge(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role,
            'TemporaryWrongOtp123!'
        );

        $otp = '482731';
        $challengeToken = Str::random(64);

        $challenge = AuthenticationChallenge::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'school_id' => $school->id,
            'otp_hash' => Hash::make($otp),
            'purpose' => 'first_login',
            'failed_attempts' => 0,
            'resend_count' => 0,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'challenge_nonce_hash' => hash_hmac(
                'sha256',
                $challengeToken,
                (string) config('app.key')
            ),
            'ip_hash' => hash_hmac(
                'sha256',
                '127.0.0.1',
                (string) config('app.key')
            ),
            'user_agent_hash' => null,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this
                ->postJson(
                    '/api/auth/first-login/verify-otp',
                    [
                        'challenge_id' => $challenge->id,
                        'challenge_token' => $challengeToken,
                        'otp' => '111111',
                    ]
                )
                ->assertUnauthorized();

            $fresh = $challenge->fresh();

            $this->assertSame(
                $attempt,
                (int) $fresh->failed_attempts
            );
        }

        $this->assertNotNull(
            $challenge->fresh()->consumed_at
        );

        $this
            ->postJson(
                '/api/auth/first-login/verify-otp',
                [
                    'challenge_id' => $challenge->id,
                    'challenge_token' => $challengeToken,
                    'otp' => $otp,
                ]
            )
            ->assertUnauthorized();
    }

    public function test_weak_permanent_password_is_rejected_without_consuming_activation_challenge(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryWeakPassword123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$challenge, $activationToken] = $this->activationChallenge(
            $user,
            $school
        );

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $challenge->id,
                    'activation_token' => $activationToken,
                    'password' => 'weakpassword',
                    'password_confirmation' => 'weakpassword',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
            ]);

        $fresh = $user->fresh();

        $this->assertTrue(
            (bool) $fresh->first_login
        );

        $this->assertTrue(
            (bool) $fresh->temporary_password
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $fresh->password_hash
            )
        );

        $this->assertNull(
            $challenge->fresh()->consumed_at
        );
    }

    public function test_second_temporary_login_does_not_destroy_verified_activation_credential(): void
    {
        Mail::fake();

        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryPreserveActivation123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        [$activationChallenge, $activationToken] = $this->activationChallenge(
            $user,
            $school
        );

        /*
         * A second correct temporary-password login may start another
         * first-login challenge, but it must never revoke an activation
         * credential that already passed OTP verification.
         */
        $this
            ->postJson(
                '/api/auth/login',
                [
                    'username' => $user->username,
                    'password' => $temporaryPassword,
                ]
            )
            ->assertStatus(202)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');

        $this->assertNull(
            $activationChallenge->fresh()->consumed_at,
            'A verified activation credential must survive another temporary-password login.'
        );

        $newPassword = 'PermanentPreserved123!';

        $this
            ->postJson(
                '/api/auth/first-login/activate',
                [
                    'challenge_id' => $activationChallenge->id,
                    'activation_token' => $activationToken,
                    'password' => $newPassword,
                    'password_confirmation' => $newPassword,
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.authenticated',
                true
            );

        $freshUser = $user->fresh();

        $this->assertFalse(
            (bool) $freshUser->first_login
        );

        $this->assertTrue(
            Hash::check(
                $newPassword,
                $freshUser->password_hash
            )
        );
    }

    public function test_activation_mail_failure_returns_controlled_response_and_leaves_no_usable_challenge(): void
    {
        [$school, $role] = $this->schoolFixture();

        $temporaryPassword = 'TemporaryMailFailure123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $temporaryPassword
        );

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(
                new \RuntimeException(
                    'Simulated SMTP transport failure.'
                )
            );

        $response = $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $temporaryPassword,
            ]
        );

        $response
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'success',
                false
            )
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('otp')
            ->assertJsonMissingPath('data.otp');

        $challenge = AuthenticationChallenge::query()
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'school_id',
                $school->id
            )
            ->where(
                'purpose',
                'first_login'
            )
            ->latest('created_at')
            ->first();

        $this->assertNotNull(
            $challenge
        );

        $this->assertNotNull(
            $challenge->consumed_at,
            'A challenge whose OTP could not be delivered must be unusable.'
        );

        $this->assertFalse(
            str_contains(
                $response->getContent(),
                'Simulated SMTP transport failure'
            ),
            'Transport exception details must never be exposed.'
        );
    }

    public function test_expired_temporary_password_is_rejected(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'ExpiredTemporary123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password,
            [
                'temporary_password_expires_at' => now()->subMinute(),
            ]
        );

        $this
            ->postJson(
                '/api/auth/login',
                [
                    'username' => $user->username,
                    'password' => $password,
                ]
            )
            ->assertUnauthorized()
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_normal_activated_school_user_still_receives_jwt(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'PermanentPassword123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password,
            [
                'first_login' => false,
                'temporary_password' => false,
                'temporary_password_expires_at' => null,
                'force_password_reset_at' => null,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $this
            ->postJson(
                '/api/auth/login',
                [
                    'username' => $user->username,
                    'password' => $password,
                ]
            )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'token',
                'data' => [
                    'token',
                ],
            ]);
    }

    public function test_first_login_school_admin_activation_response_does_not_expose_jwt(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'TemporaryActivation123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $response = $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $password,
            ]
        );

        $response
            ->assertStatus(202)
            ->assertJsonPath(
                'data.authenticated',
                false
            )
            ->assertJsonPath(
                'data.activation_required',
                true
            )
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_first_login_activation_does_not_clear_reset_state_during_login(): void
    {
        [$school, $role] = $this->schoolFixture();

        $password = 'TemporaryState123!';

        $user = $this->schoolAdmin(
            $school,
            $role,
            $password
        );

        $this->postJson(
            '/api/auth/login',
            [
                'username' => $user->username,
                'password' => $password,
            ]
        );

        $fresh = $user->fresh();

        $this->assertTrue(
            (bool) $fresh->first_login
        );

        $this->assertTrue(
            (bool) $fresh->temporary_password
        );

        $this->assertNotNull(
            $fresh->force_password_reset_at
        );

        $this->assertNull(
            $fresh->activated_at
        );
    }

    private function activationChallenge(
        User $user,
        School $school,
        array $overrides = []
    ): array {
        $activationToken = Str::random(64);

        $challenge = AuthenticationChallenge::create(
            array_merge(
                [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'school_id' => $school->id,
                    'otp_hash' => Hash::make(
                        Str::random(64)
                    ),
                    'purpose' => 'activation',
                    'failed_attempts' => 0,
                    'resend_count' => 0,
                    'last_sent_at' => now(),
                    'expires_at' => now()->addMinutes(10),
                    'consumed_at' => null,
                    'challenge_nonce_hash' => hash_hmac(
                        'sha256',
                        $activationToken,
                        (string) config('app.key')
                    ),
                    'ip_hash' => hash_hmac(
                        'sha256',
                        '127.0.0.1',
                        (string) config('app.key')
                    ),
                    'user_agent_hash' => null,
                ],
                $overrides
            )
        );

        return [
            $challenge,
            $activationToken,
        ];
    }

    private function schoolFixture(): array
    {
        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Activation Test '.Str::upper(Str::random(4)),
            'school_code' => 'ATS'.Str::upper(Str::random(8)),
            'login_prefix' => 'ATS',
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'lifecycle_version' => 1,
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
        ]);

        $role = Role::query()
            ->where(
                'role_name',
                'School Admin'
            )
            ->whereNull('school_id')
            ->where(
                'system_role',
                true
            )
            ->where(
                'active',
                true
            )
            ->firstOrFail();

        return [$school, $role];
    }

    private function schoolAdmin(
        School $school,
        Role $role,
        string $password,
        array $overrides = []
    ): User {
        return User::create(
            array_merge(
                [
                    'id' => (string) Str::uuid(),
                    'school_id' => $school->id,
                    'role_id' => $role->id,
                    'username' => 'school.admin.'.Str::lower(Str::random(8)),
                    'password_hash' => Hash::make($password),
                    'email' => 'school-admin-'.Str::lower(Str::random(8)).'@example.test',
                    'first_name' => 'School',
                    'last_name' => 'Administrator',
                    'active' => true,
                    'is_deleted' => false,
                    'first_login' => true,
                    'temporary_password' => true,
                    'temporary_password_expires_at' => now()->addHours(24),
                    'force_password_reset_at' => now(),
                    'activated_at' => null,
                    'email_verified_at' => null,
                    'auth_generation' => 1,
                ],
                $overrides
            )
        );
    }
}
