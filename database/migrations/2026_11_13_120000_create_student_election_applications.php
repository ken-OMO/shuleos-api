<?php

declare(strict_types=1);

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
        Schema::table('student_elections', function (Blueprint $t) {
            $t->string('application_method')->default('legacy');
            $t->uuid('application_policy_id')->nullable();
            $t->boolean('integrity_hold')->default(false);
        });
        Schema::create('student_election_application_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->unsignedInteger('revision');
            $t->text('terms');
            $t->char('terms_digest', 64);
            foreach (['opens_at', 'applications_close_at', 'consent_close_at', 'reviews_close_at', 'appeals_close_at'] as $field) {
                $t->timestampTz($field);
            }
            $t->boolean('photo_required');
            $t->foreignUuid('reviewer_id')->constrained('users');
            $t->foreignUuid('appeal_reviewer_id')->constrained('users');
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['election_id', 'revision'], 'election_application_policy_revision');
        });
        Schema::create('student_election_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->uuid('version_id')->nullable();
            $t->foreignUuid('candidate_id')->nullable()->constrained('student_election_candidates');
            $t->string('status')->default('pending_consent');
            $t->unique(['election_id', 'learner_id'], 'election_one_application_per_learner');
        });
        Schema::create('student_election_application_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('application_id')->constrained('student_election_applications');
            $t->foreignUuid('policy_id')->constrained('student_election_application_policies');
            $t->foreignUuid('election_position_id')->constrained('student_election_positions');
            $t->unsignedInteger('revision');
            $t->jsonb('position_snapshot');
            $t->text('manifesto');
            $t->string('slogan', 160);
            $t->char('digest', 64);
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['application_id', 'revision'], 'election_application_version_revision');
        });
        Schema::create('student_election_application_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('application_id')->constrained('student_election_applications');
            $t->foreignUuid('version_id')->constrained('student_election_application_versions');
            $t->unsignedInteger('sequence');
            $t->unique(['application_id', 'sequence'], 'election_application_event_sequence');
            $t->string('kind', 32);
            $t->string('decision', 20)->nullable();
            $t->foreignUuid('designation_id')->nullable()->constrained('student_election_guardian_designations');
            $t->foreignUuid('actor_user_id')->constrained('users');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['application_id', 'version_id', 'kind'], 'election_application_events_scope');
        });
        Schema::table('student_election_applications', function (Blueprint $t) {
            $t->foreign('version_id')->references('id')->on('student_election_application_versions');
        });
        Schema::table('student_elections', function (Blueprint $t) {
            $t->foreign('application_policy_id')->references('id')->on('student_election_application_policies');
        });
        DB::statement('ALTER TABLE student_election_application_policies ADD CONSTRAINT election_policy_shape CHECK (revision > 0 AND reviewer_id <> appeal_reviewer_id AND opens_at < applications_close_at AND applications_close_at < consent_close_at AND consent_close_at < reviews_close_at AND reviews_close_at < appeals_close_at)');
        DB::statement("ALTER TABLE student_election_applications ADD CONSTRAINT election_application_status CHECK (status IN ('pending_consent','pending_review','approved','rejected','withdrawn'))");
        DB::statement("CREATE UNIQUE INDEX election_review_once ON student_election_application_events (version_id,kind) WHERE kind IN ('review','appeal','appeal_request')");
        DB::statement("ALTER TABLE student_election_application_events ADD CONSTRAINT election_application_event_shape CHECK (COALESCE(((kind = 'consent' AND decision IN ('accept','refuse','withdraw') AND designation_id IS NOT NULL) OR (kind IN ('review','appeal') AND decision IN ('approve','reject') AND designation_id IS NULL) OR (kind IN ('appeal_request','withdraw','incident') AND decision IS NULL AND designation_id IS NULL)), false))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION election_application_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Election application evidence is append-only';
END;
$$;
SQL);
        foreach (['student_election_application_policies', 'student_election_application_versions', 'student_election_application_events'] as $table) {
            DB::statement("CREATE TRIGGER election_application_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION election_application_immutable()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION election_application_hold_ballot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE root_id uuid; blocked boolean;
BEGIN
    IF TG_TABLE_NAME = 'student_election_ballots' THEN
        root_id := NEW.election_id;
    ELSE
        SELECT election_id INTO root_id FROM student_election_rounds WHERE id = NEW.round_id;
    END IF;
    SELECT integrity_hold INTO blocked FROM student_elections WHERE id = root_id FOR SHARE;
    IF blocked IS DISTINCT FROM false THEN
        RAISE EXCEPTION 'Election integrity hold blocks new ballots';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER election_application_hold BEFORE INSERT ON student_election_ballots FOR EACH ROW EXECUTE FUNCTION election_application_hold_ballot();
CREATE TRIGGER election_application_hold BEFORE INSERT ON student_election_round_ballots FOR EACH ROW EXECUTE FUNCTION election_application_hold_ballot();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Application and consent evidence requires a reviewed forward migration.');
    }
};
