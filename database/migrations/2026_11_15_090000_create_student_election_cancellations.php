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
        Schema::create('student_election_cancellation_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->char('evidence_digest', 64);
            $t->text('reason');
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['election_id', 'created_at']);
        });
        Schema::create('student_election_cancellation_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('election_id')->constrained('student_elections');
            $t->foreignUuid('proposal_id')->unique()->constrained('student_election_cancellation_proposals');
            $t->boolean('approved');
            $t->text('reason');
            $t->jsonb('term_evidence');
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX election_one_approved_cancellation ON student_election_cancellation_decisions(election_id) WHERE approved');
        Schema::create('student_election_replacements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('original_election_id')->unique()->constrained('student_elections');
            $t->foreignUuid('fresh_election_id')->unique()->constrained('student_elections');
            $t->foreignUuid('decision_id')->unique()->constrained('student_election_cancellation_decisions');
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('created_at');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION election_cancellation_evidence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original_id uuid; root_school uuid; proposer uuid; proposal_election uuid; approved_election uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Cancellation evidence is append-only'; END IF;
    IF TG_TABLE_NAME = 'student_election_replacements' THEN original_id := NEW.original_election_id;
    ELSE original_id := NEW.election_id; END IF;
    SELECT school_id INTO root_school FROM student_elections WHERE id = original_id FOR UPDATE;
    IF root_school IS DISTINCT FROM NEW.school_id OR NOT EXISTS(SELECT 1 FROM users WHERE id = NEW.created_by AND school_id = NEW.school_id) THEN
        RAISE EXCEPTION 'Cancellation tenant scope mismatch';
    END IF;
    IF TG_TABLE_NAME = 'student_election_cancellation_decisions' THEN
        SELECT created_by, election_id INTO proposer, proposal_election FROM student_election_cancellation_proposals WHERE id = NEW.proposal_id;
        IF proposer IS NOT DISTINCT FROM NEW.created_by OR proposal_election IS DISTINCT FROM NEW.election_id THEN
            RAISE EXCEPTION 'Independent same-election review required';
        END IF;
    ELSIF TG_TABLE_NAME = 'student_election_replacements' THEN
        SELECT election_id INTO approved_election FROM student_election_cancellation_decisions WHERE id = NEW.decision_id AND approved;
        IF approved_election IS DISTINCT FROM original_id OR NEW.fresh_election_id = original_id
            OR NOT EXISTS(SELECT 1 FROM student_elections WHERE id = original_id AND status = 'cancelled' AND integrity_hold)
            OR NOT EXISTS(SELECT 1 FROM student_elections WHERE id = NEW.fresh_election_id AND school_id = NEW.school_id AND status = 'draft' AND application_policy_id IS NULL AND NOT integrity_hold) THEN
            RAISE EXCEPTION 'Reviewed cancellation and fresh same-school draft required';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION election_cancelled_terminal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status = 'cancelled' AND (NEW.status <> 'cancelled' OR NEW.integrity_hold IS DISTINCT FROM OLD.integrity_hold) THEN
        RAISE EXCEPTION 'Cancelled elections cannot resume';
    END IF;
    IF OLD.status <> 'cancelled' AND NEW.status = 'cancelled'
        AND EXISTS(SELECT 1 FROM student_election_electorates WHERE election_id = OLD.id)
        AND (NOT OLD.integrity_hold OR NOT NEW.integrity_hold OR NOT EXISTS(
            SELECT 1 FROM student_election_cancellation_decisions WHERE election_id = OLD.id AND approved)) THEN
        RAISE EXCEPTION 'Post-opening cancellation requires independent approval';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER election_cancelled_terminal BEFORE UPDATE ON student_elections FOR EACH ROW EXECUTE FUNCTION election_cancelled_terminal_guard();
SQL);
        foreach (['student_election_cancellation_proposals', 'student_election_cancellation_decisions', 'student_election_replacements'] as $table) {
            DB::unprepared("CREATE TRIGGER election_cancellation_evidence BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION election_cancellation_evidence_guard()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Cancellation evidence cannot be rolled back. Use a reviewed forward migration.');
    }
};
