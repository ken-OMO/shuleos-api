<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Build replacement constraints before releasing the existing global constraints.
        // Case-only duplicates fail the migration atomically rather than choosing an identity.
        DB::statement('CREATE UNIQUE INDEX users_school_username_unique ON users (school_id, LOWER(username)) WHERE school_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_school_email_unique ON users (school_id, LOWER(email)) WHERE school_id IS NOT NULL AND email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_platform_username_unique ON users (LOWER(username)) WHERE school_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_platform_email_unique ON users (LOWER(email)) WHERE school_id IS NULL AND email IS NOT NULL');
        // Preserve unambiguous legacy username login; only email usernames may repeat across schools.
        DB::statement("CREATE UNIQUE INDEX users_legacy_username_unique ON users (LOWER(username)) WHERE POSITION('@' IN username) = 0");
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_key');
        DB::statement('DROP INDEX IF EXISTS uq_users_username');
        DB::statement('DROP INDEX IF EXISTS uq_users_email');
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('requires_school_code')->default(false);
            $table->timestampTz('invitation_last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        // Refuse rollback if any cross-school email/username now repeats. Never delete identities.
        DB::statement('CREATE UNIQUE INDEX uq_users_username ON users (username)');
        DB::statement('CREATE UNIQUE INDEX uq_users_email ON users (email) WHERE email IS NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_username_key UNIQUE (username)');
        foreach (['users_school_username_unique', 'users_school_email_unique', 'users_platform_username_unique', 'users_platform_email_unique', 'users_legacy_username_unique'] as $index) {
            DB::statement('DROP INDEX '.$index);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['requires_school_code', 'invitation_last_sent_at']));
    }
};
