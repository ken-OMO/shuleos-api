<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_leadership_positions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->string('name');
            $t->string('code')->nullable();
            $t->text('description')->nullable();
            $t->string('scope');
            $t->foreignUuid('grade_id')->nullable()->constrained('grades');
            $t->foreignUuid('stream_id')->nullable()->constrained('streams');
            $t->string('gender_requirement')->nullable();
            $t->unsignedInteger('number_of_winners')->default(1);
            $t->foreignUuid('minimum_grade_id')->nullable()->constrained('grades');
            $t->foreignUuid('maximum_grade_id')->nullable()->constrained('grades');
            $t->boolean('active')->default(true);
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'scope', 'active', 'is_deleted']);
        });
        Schema::create('student_elections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('academic_year_id')->constrained('academic_years');
            $t->foreignUuid('term_id')->nullable()->constrained('terms');
            $t->string('title');
            $t->text('description')->nullable();
            foreach (['nomination_opens_at', 'nomination_closes_at', 'campaign_opens_at', 'campaign_closes_at'] as $f) {
                $t->timestamp($f)->nullable();
            }$t->timestamp('voting_opens_at');
            $t->timestamp('voting_closes_at');
            $t->string('status')->default('draft');
            $t->string('results_visibility')->default('hidden');
            $t->boolean('allow_self_nomination')->default(false);
            $t->boolean('require_candidate_approval')->default(true);
            $t->boolean('show_live_turnout')->default(false);
            $t->string('tie_break_rule')->default('manual_runoff');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->foreignUuid('published_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'status', 'is_deleted']);
        });
        Schema::create('student_election_positions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('position_id')->constrained('student_leadership_positions');
            $t->integer('display_order')->default(0);
            $t->integer('number_of_winners')->nullable();
            $t->jsonb('candidate_eligibility_rules')->nullable();
            $t->jsonb('voter_eligibility_rules')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['election_id', 'position_id']);
        });
        Schema::create('student_election_candidates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->foreignUuid('nominated_by_user_id')->nullable()->constrained('users');
            $t->string('nomination_type');
            $t->text('manifesto')->nullable();
            $t->string('slogan')->nullable();
            $t->string('photo_path')->nullable();
            $t->string('status')->default('pending_review');
            $t->text('review_comments')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->text('disqualification_reason')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->unique(['election_position_id', 'learner_id']);
        });
        Schema::create('student_election_voters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->boolean('eligible')->default(true);
            $t->text('ineligibility_reason')->nullable();
            $t->string('voting_token_hash')->nullable();
            $t->boolean('has_voted')->default(false);
            $t->timestamp('voted_at')->nullable();
            $t->timestamps();
            $t->unique(['election_position_id', 'learner_id']);
        });
        Schema::create('student_election_ballots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->foreignUuid('candidate_id')->constrained('student_election_candidates');
            $t->string('anonymous_ballot_token_hash')->unique();
            $t->timestamp('cast_at');
            $t->string('ballot_status')->default('valid');
            $t->timestamp('invalidated_at')->nullable();
            $t->foreignUuid('invalidated_by')->nullable()->constrained('users');
            $t->text('invalidation_reason')->nullable();
            $t->timestamp('created_at');
            $t->index(['election_position_id', 'ballot_status']);
        });
        Schema::create('student_election_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->foreignUuid('candidate_id')->constrained('student_election_candidates');
            $t->unsignedInteger('vote_count');
            $t->unsignedInteger('rank');
            $t->string('result_status');
            $t->timestamp('tallied_at');
            $t->foreignUuid('tallied_by')->constrained('users');
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['election_position_id', 'candidate_id']);
        });
        Schema::create('student_leadership_terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('position_id')->constrained('student_leadership_positions');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->foreignUuid('election_id')->nullable()->constrained('student_elections');
            $t->foreignUuid('academic_year_id')->constrained('academic_years');
            $t->foreignUuid('term_id')->nullable()->constrained('terms');
            $t->date('starts_at');
            $t->date('ends_at')->nullable();
            $t->string('status');
            $t->string('appointment_type');
            $t->foreignUuid('appointed_by')->nullable()->constrained('users');
            $t->text('removal_reason')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'position_id', 'status', 'is_deleted']);
        });
        Schema::create('student_election_audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->nullable()->constrained('student_elections');
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('entity_type');
            $t->uuid('entity_id')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestamp('created_at');
            $t->index(['school_id', 'election_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['student_election_audit_logs', 'student_leadership_terms', 'student_election_results', 'student_election_ballots', 'student_election_voters', 'student_election_candidates', 'student_election_positions', 'student_elections', 'student_leadership_positions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
