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
        Schema::create('student_election_guardian_designations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->unsignedInteger('revision');
            $t->string('action', 20);
            $t->foreignUuid('parent_id')->nullable()->constrained('parents');
            $t->foreignUuid('parent_user_id')->nullable()->constrained('users');
            $t->foreignUuid('learner_parent_id')->nullable()->constrained('learner_parents');
            $t->foreignUuid('actor_user_id')->constrained('users');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['election_id', 'learner_id', 'revision'], 'election_guardian_revision');
            $t->index(['school_id', 'election_id', 'learner_id'], 'election_guardian_scope');
        });
        DB::statement("ALTER TABLE student_election_guardian_designations ADD CONSTRAINT election_guardian_shape CHECK (revision > 0 AND length(btrim(reason)) BETWEEN 10 AND 1000 AND ((action = 'designate' AND parent_id IS NOT NULL AND parent_user_id IS NOT NULL AND learner_parent_id IS NOT NULL) OR (action = 'revoke' AND parent_id IS NULL AND parent_user_id IS NULL AND learner_parent_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION election_guardian_evidence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Election guardian evidence is append-only';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM student_elections WHERE id = NEW.election_id AND school_id = NEW.school_id)
       OR NOT EXISTS (SELECT 1 FROM learners WHERE id = NEW.learner_id AND school_id = NEW.school_id)
       OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.actor_user_id AND school_id = NEW.school_id) THEN
        RAISE EXCEPTION 'Election guardian tenant scope mismatch';
    END IF;
    IF NEW.action = 'designate' AND (
       NOT EXISTS (SELECT 1 FROM parents WHERE id = NEW.parent_id AND school_id = NEW.school_id AND user_id = NEW.parent_user_id)
       OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.parent_user_id AND school_id = NEW.school_id)
       OR NOT EXISTS (SELECT 1 FROM learner_parents WHERE id = NEW.learner_parent_id AND learner_id = NEW.learner_id AND parent_id = NEW.parent_id)) THEN
        RAISE EXCEPTION 'Election guardian relationship scope mismatch';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER election_guardian_evidence BEFORE INSERT OR UPDATE OR DELETE ON student_election_guardian_designations
FOR EACH ROW EXECUTE FUNCTION election_guardian_evidence_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Guardian evidence requires a reviewed forward migration.');
    }
};
