<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }
        Schema::create('student_election_electorates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('election_position_id')->unique()->constrained('student_election_positions');
            $t->jsonb('learner_ids');
            $t->char('digest', 64);
        });
        Schema::create('student_election_rounds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->foreignUuid('source_snapshot_id')->constrained('student_election_tally_snapshots');
            $t->foreignUuid('parent_round_id')->nullable()->unique();
            $t->unsignedInteger('sequence');
            $t->string('kind');
            $t->string('status')->default('scheduled');
            $t->jsonb('definition');
            $t->char('definition_digest', 64);
            $t->jsonb('outcome')->nullable();
            $t->char('outcome_digest', 64)->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['election_position_id', 'sequence'], 'student_round_sequence');
            $t->index(['election_id', 'sequence'], 'student_round_election');
        });
        Schema::table('student_election_rounds', function (Blueprint $t) {
            $t->foreign('parent_round_id')->references('id')->on('student_election_rounds');
        });
        DB::statement("ALTER TABLE student_election_rounds ADD CONSTRAINT student_round_kind CHECK (kind IN ('runoff', 'confirmation', 'repeat_poll'))");
        DB::statement("ALTER TABLE student_election_rounds ADD CONSTRAINT student_round_status CHECK (status IN ('scheduled', 'open', 'closed', 'tallied'))");
        Schema::create('student_election_round_voters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('round_id')->constrained('student_election_rounds');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->boolean('has_voted')->default(false);
            $t->char('approval_hash', 64)->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->foreignUuid('attendance_id')->nullable()->constrained('learner_attendance');
            $t->timestampTz('approval_expires_at')->nullable();
            $t->unique(['round_id', 'learner_id'], 'student_round_voter_once');
        });
        Schema::create('student_election_round_ballots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('round_id')->constrained('student_election_rounds');
            $t->foreignUuid('candidate_id')->nullable()->constrained('student_election_candidates');
            $t->string('choice');
            $t->char('random_marker', 64)->unique();
            $t->index('round_id');
        });
        DB::statement("ALTER TABLE student_election_round_ballots ADD CONSTRAINT student_round_choice CHECK ((choice = 'selection' AND candidate_id IS NOT NULL) OR (choice IN ('approve', 'reject', 'abstain') AND candidate_id IS NULL))");
        Schema::table('student_election_declarations', function (Blueprint $t) {
            $t->char('resolution_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Election evidence requires a reviewed forward migration.');
    }
};
