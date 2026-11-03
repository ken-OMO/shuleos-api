<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Boarding occurrences require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        $definition = DB::selectOne(
            "SELECT pg_get_functiondef('public.boarding_submission_validate()'::regprocedure) AS definition"
        )->definition;

        foreach ([
            "'schema_version', 1,",
            "'contributors', (",
            '    IF length(btrim(draft.summary)) = 0 THEN',
        ] as $anchor) {
            if (substr_count($definition, $anchor) !== 1) {
                throw new RuntimeException('Unexpected submission function boundary.');
            }
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE boarding_occurrence_function_backup (
    definition text NOT NULL
);

CREATE UNIQUE INDEX boarding_revision_report_context_key
ON boarding_daily_report_revisions (school_id, report_id, id);

CREATE TABLE boarding_occurrence_categories (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    category_name varchar(100) NOT NULL CHECK (length(btrim(category_name)) > 0),
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    active boolean NOT NULL DEFAULT true,
    created_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_occurrence_category_owner_key UNIQUE (school_id, id),
    CONSTRAINT boarding_occurrence_category_actor_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX boarding_occurrence_category_name_key
ON boarding_occurrence_categories (school_id, lower(btrim(category_name)));

CREATE OR REPLACE FUNCTION public.boarding_category_revision_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.category_name, NEW.active)
       IS DISTINCT FROM (OLD.category_name, OLD.active) THEN
        NEW.lock_version := OLD.lock_version + 1;
    ELSE
        NEW.lock_version := OLD.lock_version;
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER boarding_category_revision_validate
BEFORE UPDATE ON boarding_occurrence_categories
FOR EACH ROW EXECUTE FUNCTION public.boarding_category_revision_validate();

CREATE TABLE boarding_report_occurrence_events (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    report_id uuid NOT NULL,
    revision_id uuid NOT NULL,
    event_id uuid NOT NULL,
    version integer NOT NULL CHECK (version > 0),
    previous_id uuid,
    event_state varchar(20) NOT NULL CHECK (event_state IN ('recorded', 'voided')),
    category_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL,
    description text NOT NULL
        CHECK (length(btrim(description)) > 0 AND char_length(description) <= 5000),
    actions_taken text NOT NULL DEFAULT '' CHECK (char_length(actions_taken) <= 5000),
    follow_up_required boolean NOT NULL DEFAULT false,
    follow_up_notes text NOT NULL DEFAULT '' CHECK (char_length(follow_up_notes) <= 2000),
    reason varchar(500),
    recorded_by uuid NOT NULL,
    assignment_id uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_occurrence_owner_key UNIQUE (school_id, id),
    CONSTRAINT boarding_occurrence_version_key
        UNIQUE (school_id, report_id, event_id, version),
    CONSTRAINT boarding_occurrence_revision_fk
        FOREIGN KEY (school_id, report_id, revision_id)
        REFERENCES boarding_daily_report_revisions (school_id, report_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT boarding_occurrence_previous_fk
        FOREIGN KEY (school_id, previous_id)
        REFERENCES boarding_report_occurrence_events (school_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT boarding_occurrence_category_fk
        FOREIGN KEY (school_id, category_id)
        REFERENCES boarding_occurrence_categories (school_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT boarding_occurrence_actor_fk
        FOREIGN KEY (school_id, recorded_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_occurrence_assignment_fk
        FOREIGN KEY (school_id, assignment_id)
        REFERENCES hostel_staff_assignments (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_occurrence_follow_up_check CHECK (
        NOT follow_up_required OR length(btrim(follow_up_notes)) > 0
    )
);

CREATE OR REPLACE FUNCTION public.boarding_report_occurrence_snapshot(
    owner_school uuid, owner_report uuid, target_revision integer
) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'id', latest.id,
        'event_id', latest.event_id,
        'version', latest.version,
        'previous_id', latest.previous_id,
        'revision_id', latest.revision_id,
        'event_state', latest.event_state,
        'category_id', latest.category_id,
        'category_name', category.category_name,
        'occurred_at', latest.occurred_at,
        'description', latest.description,
        'actions_taken', latest.actions_taken,
        'follow_up_required', latest.follow_up_required,
        'follow_up_notes', latest.follow_up_notes,
        'reason', latest.reason,
        'recorded_by', latest.recorded_by,
        'assignment_id', latest.assignment_id,
        'recorded_at', latest.recorded_at
    ) ORDER BY latest.occurred_at, latest.event_id), '[]'::jsonb)
    FROM (
        SELECT DISTINCT ON (event.event_id) event.*
        FROM boarding_report_occurrence_events event
        JOIN boarding_daily_report_revisions revision
          ON revision.school_id = event.school_id
         AND revision.report_id = event.report_id
         AND revision.id = event.revision_id
        WHERE event.school_id = owner_school
          AND event.report_id = owner_report
          AND revision.revision_number <= target_revision
        ORDER BY event.event_id, event.version DESC
    ) latest
    JOIN boarding_occurrence_categories category
      ON category.school_id = latest.school_id AND category.id = latest.category_id
$$;

CREATE OR REPLACE FUNCTION public.boarding_occurrence_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    draft boarding_daily_report_revisions%ROWTYPE;
    previous boarding_report_occurrence_events%ROWTYPE;
    obligation boarding_daily_obligations%ROWTYPE;
    category_active boolean;
BEGIN
    PERFORM 1 FROM boarding_daily_reports
    WHERE school_id = NEW.school_id AND id = NEW.report_id FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Occurrence report tenant mismatch' USING ERRCODE = '23503';
    END IF;

    SELECT * INTO draft FROM boarding_daily_report_revisions
    WHERE school_id = NEW.school_id
      AND report_id = NEW.report_id AND id = NEW.revision_id FOR UPDATE;

    IF NOT FOUND OR NOT boarding_report_actor_valid(
        NEW.school_id, NEW.report_id, NEW.recorded_by, NEW.assignment_id
    ) THEN
        RAISE EXCEPTION 'Occurrence responsibility mismatch' USING ERRCODE = '23503';
    END IF;

    IF EXISTS (
        SELECT 1 FROM boarding_daily_report_submissions
        WHERE school_id = NEW.school_id AND revision_id = NEW.revision_id
    ) THEN
        RAISE EXCEPTION 'Submitted occurrence evidence is frozen'
            USING ERRCODE = '23514';
    END IF;

    SELECT o.* INTO obligation
    FROM boarding_daily_obligations o
    JOIN boarding_daily_reports r
      ON r.school_id = o.school_id AND r.obligation_id = o.id
    WHERE r.school_id = NEW.school_id AND r.id = NEW.report_id;

    IF (NEW.occurred_at AT TIME ZONE obligation.timezone)::date
           <> obligation.reporting_date
       OR NEW.occurred_at > clock_timestamp()
    THEN
        RAISE EXCEPTION 'Occurrence time is outside reporting date'
            USING ERRCODE = '23514';
    END IF;

    SELECT active INTO category_active FROM boarding_occurrence_categories
    WHERE school_id = NEW.school_id AND id = NEW.category_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Occurrence category tenant mismatch' USING ERRCODE = '23503';
    END IF;

    IF NEW.event_state = 'recorded' AND NOT category_active THEN
        RAISE EXCEPTION 'Occurrence category is inactive' USING ERRCODE = '23514';
    END IF;

    SELECT * INTO previous FROM boarding_report_occurrence_events
    WHERE school_id = NEW.school_id
      AND report_id = NEW.report_id AND event_id = NEW.event_id
    ORDER BY version DESC LIMIT 1;

    IF previous.id IS NULL THEN
        IF NEW.version <> 1 OR NEW.previous_id IS NOT NULL
           OR NEW.event_state <> 'recorded' OR NEW.reason IS NOT NULL
        THEN
            RAISE EXCEPTION 'Invalid initial occurrence version'
                USING ERRCODE = '23514';
        END IF;
    ELSE
        IF NEW.version <> previous.version + 1
           OR NEW.previous_id IS DISTINCT FROM previous.id
           OR previous.event_state = 'voided'
           OR NEW.reason IS NULL OR length(btrim(NEW.reason)) = 0
        THEN
            RAISE EXCEPTION 'Invalid occurrence correction or void'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.event_state = 'voided'
           AND (NEW.category_id, NEW.occurred_at, NEW.description,
                NEW.actions_taken, NEW.follow_up_required, NEW.follow_up_notes)
               IS DISTINCT FROM
               (previous.category_id, previous.occurred_at, previous.description,
                previous.actions_taken, previous.follow_up_required, previous.follow_up_notes)
        THEN
            RAISE EXCEPTION 'Voiding must retain the previous occurrence content'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_occurrence_register_contribution()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE boarding_daily_report_revisions
    SET lock_version = lock_version + 1,
        no_occurrences = false,
        updated_by = NEW.recorded_by,
        updated_assignment_id = NEW.assignment_id
    WHERE school_id = NEW.school_id AND id = NEW.revision_id;
    RETURN NEW;
END $$;

CREATE TRIGGER boarding_occurrence_validate
BEFORE INSERT ON boarding_report_occurrence_events
FOR EACH ROW EXECUTE FUNCTION boarding_occurrence_validate();

CREATE TRIGGER boarding_occurrence_register_contribution
AFTER INSERT ON boarding_report_occurrence_events
FOR EACH ROW EXECUTE FUNCTION boarding_occurrence_register_contribution();

CREATE TRIGGER boarding_occurrence_event_immutable
BEFORE UPDATE OR DELETE ON boarding_report_occurrence_events
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();

CREATE TRIGGER boarding_occurrence_category_no_delete
BEFORE DELETE ON boarding_occurrence_categories
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();
SQL);

        DB::table('boarding_occurrence_function_backup')->insert([
            'definition' => $definition,
        ]);

        $declarationCheck = <<<'SQL'
    IF draft.no_occurrences <> (NOT EXISTS (
        SELECT 1 FROM jsonb_array_elements(
            boarding_report_occurrence_snapshot(
                NEW.school_id, draft.report_id, draft.revision_number
            )
        ) item WHERE item->>'event_state' = 'recorded'
    )) THEN
        RAISE EXCEPTION 'No-occurrence declaration conflicts with occurrence evidence'
            USING ERRCODE = '23514';
    END IF;

SQL;

        $definition = str_replace(
            '    IF length(btrim(draft.summary)) = 0 THEN',
            $declarationCheck."\n    IF length(btrim(draft.summary)) = 0 THEN",
            $definition
        );

        $definition = str_replace(
            "'schema_version', 1,",
            "'schema_version', 2,",
            $definition
        );

        $definition = str_replace(
            "'contributors', (",
            "'occurrences', boarding_report_occurrence_snapshot(NEW.school_id, draft.report_id, draft.revision_number),\n        'contributors', (",
            $definition
        );

        DB::unprepared($definition);
    }

    public function down(): void
    {
        if (DB::table('boarding_report_occurrence_events')->exists()
            || DB::table('boarding_occurrence_categories')->exists()) {
            throw new RuntimeException(
                'Retain boarding occurrence evidence; use a reviewed forward migration.'
            );
        }

        $definition = DB::table('boarding_occurrence_function_backup')->value('definition');
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('Previous submission function is unavailable.');
        }

        DB::unprepared($definition);

        DB::unprepared(<<<'SQL'
DROP TABLE boarding_report_occurrence_events;
DROP TABLE boarding_occurrence_categories;
DROP FUNCTION public.boarding_category_revision_validate();
DROP TABLE boarding_occurrence_function_backup;
DROP INDEX boarding_revision_report_context_key;
DROP FUNCTION public.boarding_occurrence_register_contribution();
DROP FUNCTION public.boarding_occurrence_validate();
DROP FUNCTION public.boarding_report_occurrence_snapshot(uuid, uuid, integer);
SQL);
    }
};
