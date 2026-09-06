<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            /*
             * Permanent human-friendly username prefix.
             *
             * Example:
             * Lakeview Junior School
             * login_prefix = LJS
             *
             * This is intentionally NOT unique globally because different
             * schools may naturally have the same initials. school_code
             * remains the tenant namespace.
             */
            $table->string('login_prefix', 12)
                ->nullable()
                ->index();
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Email must be verified before it is trusted for authentication
             * challenges and recovery.
             */
            $table->timestamp('email_verified_at')
                ->nullable()
                ->index();

            /*
             * Initial/provisioned password expiration.
             */
            $table->timestamp('temporary_password_expires_at')
                ->nullable()
                ->index();

            /*
             * Marks whether the current credential was system-generated.
             * It must never be confused with a normal user password.
             */
            $table->boolean('temporary_password')
                ->default(false)
                ->index();

            /*
             * Allows invitations to be invalidated/reissued without keeping
             * plaintext invitation credentials.
             */
            $table->unsignedInteger('invitation_generation')
                ->default(1);

            /*
             * Useful for security notification and auditing.
             */
            $table->timestamp('activated_at')
                ->nullable();
        });

        /*
         * A password-verified user does NOT receive a JWT immediately.
         * Instead an authentication challenge is created here.
         */
        Schema::create('authentication_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
             * Nullable because the Platform Owner has no school.
             */
            $table->foreignUuid('school_id')
                ->nullable()
                ->constrained('schools')
                ->cascadeOnDelete();

            /*
             * Never store the OTP itself.
             */
            $table->string('otp_hash', 255);

            /*
             * login
             * first_login
             * password_reset
             * sensitive_action
             *
             * We start with login but deliberately make the table reusable.
             */
            $table->string('purpose', 40)
                ->default('login');

            $table->unsignedSmallInteger('failed_attempts')
                ->default(0);

            $table->unsignedSmallInteger('resend_count')
                ->default(0);

            $table->timestamp('last_sent_at');

            $table->timestamp('expires_at')
                ->index();

            $table->timestamp('consumed_at')
                ->nullable()
                ->index();

            /*
             * Random value representing the exact authentication attempt.
             * It prevents an OTP from being moved between login attempts.
             */
            $table->string('challenge_nonce_hash', 64);

            /*
             * Only hashes/fingerprints are retained.
             * Do not store Authorization headers or browser tokens here.
             */
            $table->string('ip_hash', 64)
                ->nullable();

            $table->string('user_agent_hash', 64)
                ->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'purpose',
                'consumed_at',
                'expires_at',
            ], 'auth_challenges_user_state_idx');
        });

        /*
         * Separate rate-limit/security state from the User row.
         *
         * This lets us defend against:
         * - one IP attacking many accounts
         * - one account attacked from many IPs
         * - one school code being enumerated
         * - credential stuffing
         *
         * without creating permanent account-lockout DoS.
         */
        Schema::create('authentication_throttles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * Examples:
             * ip
             * school
             * account
             * ip_account
             * otp
             */
            $table->string('dimension', 30);

            /*
             * HMAC/hash of the identifier — do not put raw IPs,
             * usernames or school codes in the key.
             */
            $table->string('key_hash', 64);

            $table->unsignedInteger('attempts')
                ->default(0);

            $table->timestamp('window_started_at');

            $table->timestamp('blocked_until')
                ->nullable()
                ->index();

            $table->timestamp('last_attempt_at')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'dimension',
                'key_hash',
            ], 'auth_throttles_dimension_key_unique');
        });

        /*
         * Track authenticated browser/device sessions independently of JWT.
         *
         * No JWT or refresh token is stored here.
         */
        Schema::create('authentication_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignUuid('school_id')
                ->nullable()
                ->constrained('schools')
                ->cascadeOnDelete();

            /*
             * JWT jti fingerprint/hash where available.
             */
            $table->string('token_jti_hash', 64)
                ->nullable()
                ->index();

            $table->string('device_fingerprint_hash', 64)
                ->nullable()
                ->index();

            $table->string('user_agent_summary', 255)
                ->nullable();

            $table->string('ip_hash', 64)
                ->nullable();

            $table->timestamp('authenticated_at');

            $table->timestamp('last_seen_at')
                ->nullable();

            $table->timestamp('revoked_at')
                ->nullable()
                ->index();

            $table->string('revocation_reason', 100)
                ->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'revoked_at',
            ], 'auth_sessions_user_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_sessions');
        Schema::dropIfExists('authentication_throttles');
        Schema::dropIfExists('authentication_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at',
                'temporary_password_expires_at',
                'temporary_password',
                'invitation_generation',
                'activated_at',
            ]);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('login_prefix');
        });
    }
};
