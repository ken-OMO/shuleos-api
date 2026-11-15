<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }
        Schema::table('student_elections', function (Blueprint $t) {
            $t->string('ballot_method')->default('legacy_single_selection');
            $t->string('ballot_timezone', 64)->nullable();
        });
        Schema::table('student_election_electorates', function (Blueprint $t) {
            $t->string('ballot_method')->default('legacy_single_selection');
        });
        DB::statement('ALTER TABLE student_election_ballots ALTER COLUMN candidate_id DROP NOT NULL');
        Schema::table('student_election_ballots', function (Blueprint $t) {
            $t->string('choice')->default('selection');
        });
        DB::statement("ALTER TABLE student_election_ballots ADD CONSTRAINT student_election_ballot_shape CHECK ((choice = 'selection' AND candidate_id IS NOT NULL) OR (choice IN ('approve','reject','abstain') AND candidate_id IS NULL))");
        Schema::create('student_election_stations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('teacher_assignment_id')->constrained('teacher_assignments');
            $t->foreignUuid('stream_id')->constrained('streams');
            $t->string('label', 60);
            $t->boolean('active')->default(true);
            $t->char('pairing_hash', 64)->nullable();
            $t->timestampTz('pairing_expires_at')->nullable();
            $t->char('device_hash', 64)->nullable();
            $t->timestampTz('device_expires_at')->nullable();
            $t->unique(['election_id', 'stream_id', 'label'], 'election_station_label');
        });
        Schema::create('student_election_station_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('station_id')->constrained('student_election_stations');
            $t->foreignUuid('learner_id')->nullable()->constrained('learners');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->foreignUuid('attendance_id')->nullable()->constrained('learner_attendance');
            $t->string('status');
            $t->timestampTz('expires_at')->nullable();
            $t->jsonb('definition')->nullable();
            $t->char('definition_digest', 64)->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX election_station_busy ON student_election_station_sessions (station_id) WHERE status IN ('pending','approved')");
        DB::statement("CREATE UNIQUE INDEX election_station_learner_busy ON student_election_station_sessions (election_id,learner_id) WHERE status IN ('pending','approved')");
        DB::statement("ALTER TABLE student_election_station_sessions ADD CONSTRAINT election_station_session_status CHECK (status IN ('pending','approved','submitted','denied','expired','cancelled'))");
        DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(),
            'permission_name' => 'operate_student_election_stations', 'module_name' => 'Student Elections',
            'description' => 'Operate election stations only for a current assigned class', 'created_at' => now()]);
        // No automatic role grants or school activation.
    }

    public function down(): void
    {
        throw new RuntimeException('Election evidence requires a reviewed forward migration.');
    }
};
