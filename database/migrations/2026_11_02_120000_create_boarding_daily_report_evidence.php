<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Boarding reports require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX boarding_assignment_owner_key
ON hostel_staff_assignments (school_id, id);

CREATE TABLE boarding_daily_reports (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    obligation_id uuid NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_report_owner_key UNIQUE (school_id, id),
    CONSTRAINT boarding_report_obligation_key UNIQUE (school_id, obligation_id),
    CONSTRAINT boarding_report_obligation_fk
        FOREIGN KEY (school_id, obligation_id)
        REFERENCES boarding_daily_obligations (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_report_actor_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT
);

CREATE TABLE boarding_daily_report_revisions (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    report_id uuid NOT NULL,
    revision_number integer NOT NULL CHECK (revision_number > 0),
    lock_version integer NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    summary text NOT NULL DEFAULT '' CHECK (char_length(summary) <= 10000),
    no_occurrences boolean NOT NULL DEFAULT false,
    created_by uuid NOT NULL,
    created_assignment_id uuid NOT NULL,
    updated_by uuid NOT NULL,
    updated_assignment_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_revision_owner_key UNIQUE (school_id, id),
    CONSTRAINT boarding_revision_number_key UNIQUE (school_id, report_id, revision_number),
    CONSTRAINT boarding_revision_report_fk
        FOREIGN KEY (school_id, report_id)
        REFERENCES boarding_daily_reports (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_revision_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_revision_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_revision_creator_assignment_fk
        FOREIGN KEY (school_id, created_assignment_id)
        REFERENCES hostel_staff_assignments (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_revision_updater_assignment_fk
        FOREIGN KEY (school_id, updated_assignment_id)
        REFERENCES hostel_staff_assignments (school_id, id) ON DELETE RESTRICT
);

CREATE TABLE boarding_daily_report_contributors (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    revision_id uuid NOT NULL,
    user_id uuid NOT NULL,
    assignment_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_contributor_identity_key UNIQUE (school_id, revision_id, user_id),
    CONSTRAINT boarding_contributor_revision_fk
        FOREIGN KEY (school_id, revision_id)
        REFERENCES boarding_daily_report_revisions (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_contributor_user_fk
        FOREIGN KEY (school_id, user_id)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_contributor_assignment_fk
        FOREIGN KEY (school_id, assignment_id)
        REFERENCES hostel_staff_assignments (school_id, id) ON DELETE RESTRICT
);

CREATE TABLE boarding_daily_report_submissions (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    revision_id uuid NOT NULL,
    submitted_by uuid NOT NULL,
    assignment_id uuid NOT NULL,
    submitted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    snapshot jsonb NOT NULL,
    CONSTRAINT boarding_submission_revision_key UNIQUE (school_id, revision_id),
    CONSTRAINT boarding_submission_context_key UNIQUE (school_id, revision_id, id),
    CONSTRAINT boarding_submission_revision_fk
        FOREIGN KEY (school_id, revision_id)
        REFERENCES boarding_daily_report_revisions (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_submission_user_fk
        FOREIGN KEY (school_id, submitted_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_submission_assignment_fk
        FOREIGN KEY (school_id, assignment_id)
        REFERENCES hostel_staff_assignments (school_id, id) ON DELETE RESTRICT
);

CREATE TABLE boarding_daily_report_reviews (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    revision_id uuid NOT NULL,
    submission_id uuid NOT NULL,
    reviewed_by uuid NOT NULL,
    decision varchar(30) NOT NULL,
    comment text,
    reviewed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT boarding_review_revision_key UNIQUE (school_id, revision_id),
    CONSTRAINT boarding_review_submission_fk
        FOREIGN KEY (school_id, revision_id, submission_id)
        REFERENCES boarding_daily_report_submissions (school_id, revision_id, id)
        ON DELETE RESTRICT,
    CONSTRAINT boarding_review_actor_fk
        FOREIGN KEY (school_id, reviewed_by)
        REFERENCES users (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT boarding_review_values_check CHECK (
        decision IN ('reviewed', 'changes_requested')
        AND (comment IS NULL OR char_length(comment) <= 4000)
        AND (
            decision <> 'changes_requested'
            OR (comment IS NOT NULL AND length(btrim(comment)) > 0)
        )
    )
);

CREATE OR REPLACE FUNCTION public.boarding_report_actor_valid(
    owner_school uuid, owner_report uuid, actor uuid, episode uuid
) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1
        FROM boarding_daily_reports report
        JOIN boarding_daily_obligations obligation
          ON obligation.school_id = report.school_id
         AND obligation.id = report.obligation_id
        JOIN hostel_staff_assignments assignment
          ON assignment.school_id = report.school_id
         AND assignment.hostel_id = obligation.hostel_id
         AND assignment.id = episode
         AND assignment.user_id = actor
        JOIN users person
          ON person.school_id = assignment.school_id
         AND person.id = assignment.user_id
        WHERE report.school_id = owner_school
          AND report.id = owner_report
          AND assignment.effective_from <= obligation.reporting_date
          AND (
              assignment.effective_to IS NULL
              OR assignment.effective_to >= obligation.reporting_date
          )
          AND person.active IS TRUE
          AND person.is_deleted IS FALSE
          AND person.suspended_at IS NULL
    )
$$;

CREATE OR REPLACE FUNCTION public.boarding_revision_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    previous boarding_daily_report_revisions%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Report revisions cannot be deleted' USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        PERFORM 1 FROM boarding_daily_reports
        WHERE school_id = NEW.school_id AND id = NEW.report_id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Report tenant mismatch' USING ERRCODE = '23503';
        END IF;

        SELECT * INTO previous FROM boarding_daily_report_revisions
        WHERE school_id = NEW.school_id AND report_id = NEW.report_id
        ORDER BY revision_number DESC LIMIT 1;

        IF NEW.revision_number <> COALESCE(previous.revision_number, 0) + 1
           OR NEW.lock_version <> 1
           OR NEW.updated_by <> NEW.created_by
           OR NEW.updated_assignment_id <> NEW.created_assignment_id
        THEN
            RAISE EXCEPTION 'Invalid initial revision identity' USING ERRCODE = '23514';
        END IF;

        IF previous.id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM boarding_daily_report_reviews
            WHERE school_id = NEW.school_id
              AND revision_id = previous.id
              AND decision = 'changes_requested'
        ) THEN
            RAISE EXCEPTION 'A correction requires changes requested'
                USING ERRCODE = '23514';
        END IF;

        IF NOT boarding_report_actor_valid(
            NEW.school_id, NEW.report_id, NEW.created_by, NEW.created_assignment_id
        ) THEN
            RAISE EXCEPTION 'Invalid report responsibility context'
                USING ERRCODE = '23503';
        END IF;
    ELSE
        IF (NEW.id, NEW.school_id, NEW.report_id, NEW.revision_number,
            NEW.created_by, NEW.created_assignment_id, NEW.created_at)
           IS DISTINCT FROM
           (OLD.id, OLD.school_id, OLD.report_id, OLD.revision_number,
            OLD.created_by, OLD.created_assignment_id, OLD.created_at)
        THEN
            RAISE EXCEPTION 'Revision ownership is immutable' USING ERRCODE = '23514';
        END IF;

        IF EXISTS (
            SELECT 1 FROM boarding_daily_report_submissions
            WHERE school_id = OLD.school_id AND revision_id = OLD.id
        ) THEN
            RAISE EXCEPTION 'Submitted revision is immutable' USING ERRCODE = '23514';
        END IF;

        IF NEW.lock_version <> OLD.lock_version + 1 THEN
            RAISE EXCEPTION 'Invalid draft version increment' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NOT boarding_report_actor_valid(
        NEW.school_id, NEW.report_id, NEW.updated_by, NEW.updated_assignment_id
    ) THEN
        RAISE EXCEPTION 'Invalid draft writer responsibility' USING ERRCODE = '23503';
    END IF;

    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_contributor_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    draft boarding_daily_report_revisions%ROWTYPE;
BEGIN
    SELECT * INTO draft FROM boarding_daily_report_revisions
    WHERE school_id = NEW.school_id AND id = NEW.revision_id FOR UPDATE;

    IF NOT FOUND OR NOT boarding_report_actor_valid(
        NEW.school_id, draft.report_id, NEW.user_id, NEW.assignment_id
    ) THEN
        RAISE EXCEPTION 'Invalid contributor responsibility' USING ERRCODE = '23503';
    END IF;

    IF EXISTS (
        SELECT 1 FROM boarding_daily_report_submissions
        WHERE school_id = NEW.school_id AND revision_id = NEW.revision_id
    ) THEN
        RAISE EXCEPTION 'Submitted contributors are frozen' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_revision_contributor()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO boarding_daily_report_contributors
        (id, school_id, revision_id, user_id, assignment_id)
    VALUES
        (gen_random_uuid(), NEW.school_id, NEW.id,
         NEW.updated_by, NEW.updated_assignment_id)
    ON CONFLICT (school_id, revision_id, user_id) DO NOTHING;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_submission_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    draft boarding_daily_report_revisions%ROWTYPE;
    expected_snapshot jsonb;
BEGIN
    SELECT * INTO draft FROM boarding_daily_report_revisions
    WHERE school_id = NEW.school_id AND id = NEW.revision_id FOR UPDATE;

    IF NOT FOUND OR NOT boarding_report_actor_valid(
        NEW.school_id, draft.report_id, NEW.submitted_by, NEW.assignment_id
    ) THEN
        RAISE EXCEPTION 'Invalid submitter responsibility' USING ERRCODE = '23503';
    END IF;

    IF length(btrim(draft.summary)) = 0 THEN
        RAISE EXCEPTION 'A report summary is required' USING ERRCODE = '23514';
    END IF;

    INSERT INTO boarding_daily_report_contributors
        (id, school_id, revision_id, user_id, assignment_id)
    VALUES
        (gen_random_uuid(), NEW.school_id, NEW.revision_id,
         NEW.submitted_by, NEW.assignment_id)
    ON CONFLICT (school_id, revision_id, user_id) DO NOTHING;

    SELECT jsonb_build_object(
        'schema_version', 1,
        'report_id', draft.report_id,
        'revision_id', draft.id,
        'revision_number', draft.revision_number,
        'summary', draft.summary,
        'no_occurrences', draft.no_occurrences,
        'hostel_id', obligation.hostel_id,
        'hostel_name', hostel.hostel_name,
        'reporting_date', obligation.reporting_date,
        'timezone', obligation.timezone,
        'due_at', obligation.due_at,
        'contributors', (
            SELECT COALESCE(jsonb_agg(jsonb_build_object(
                'user_id', contributor.user_id,
                'assignment_id', contributor.assignment_id,
                'responsibility_role', assignment.responsibility_role,
                'first_name', person.first_name,
                'last_name', person.last_name
            ) ORDER BY contributor.user_id), '[]'::jsonb)
            FROM boarding_daily_report_contributors contributor
            JOIN hostel_staff_assignments assignment
              ON assignment.school_id = contributor.school_id
             AND assignment.id = contributor.assignment_id
            JOIN users person
              ON person.school_id = contributor.school_id
             AND person.id = contributor.user_id
            WHERE contributor.school_id = NEW.school_id
              AND contributor.revision_id = NEW.revision_id
        )
    ) INTO expected_snapshot
    FROM boarding_daily_reports report
    JOIN boarding_daily_obligations obligation
      ON obligation.school_id = report.school_id
     AND obligation.id = report.obligation_id
    JOIN hostels hostel
      ON hostel.school_id = obligation.school_id
     AND hostel.id = obligation.hostel_id
    WHERE report.school_id = NEW.school_id AND report.id = draft.report_id;

    IF NEW.snapshot IS NOT NULL AND NEW.snapshot <> expected_snapshot THEN
        RAISE EXCEPTION 'Submission snapshot mismatch' USING ERRCODE = '23514';
    END IF;

    NEW.snapshot := expected_snapshot;
    NEW.submitted_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_review_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM boarding_daily_report_revisions
    WHERE school_id = NEW.school_id AND id = NEW.revision_id FOR UPDATE;

    IF NOT FOUND OR NOT EXISTS (
        SELECT 1 FROM boarding_daily_report_submissions
        WHERE school_id = NEW.school_id
          AND revision_id = NEW.revision_id AND id = NEW.submission_id
    ) THEN
        RAISE EXCEPTION 'Review submission context mismatch' USING ERRCODE = '23503';
    END IF;

    IF EXISTS (
        SELECT 1 FROM boarding_daily_report_contributors
        WHERE school_id = NEW.school_id
          AND revision_id = NEW.revision_id AND user_id = NEW.reviewed_by
    ) THEN
        RAISE EXCEPTION 'Contributors cannot review their revision'
            USING ERRCODE = '23514';
    END IF;

    NEW.reviewed_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER boarding_revision_validate
BEFORE INSERT OR UPDATE OR DELETE ON boarding_daily_report_revisions
FOR EACH ROW EXECUTE FUNCTION boarding_revision_validate();

CREATE TRIGGER boarding_revision_contributor
AFTER INSERT OR UPDATE ON boarding_daily_report_revisions
FOR EACH ROW EXECUTE FUNCTION boarding_revision_contributor();

CREATE TRIGGER boarding_contributor_validate
BEFORE INSERT ON boarding_daily_report_contributors
FOR EACH ROW EXECUTE FUNCTION boarding_contributor_validate();

CREATE TRIGGER boarding_submission_validate
BEFORE INSERT ON boarding_daily_report_submissions
FOR EACH ROW EXECUTE FUNCTION boarding_submission_validate();

CREATE TRIGGER boarding_review_validate
BEFORE INSERT ON boarding_daily_report_reviews
FOR EACH ROW EXECUTE FUNCTION boarding_review_validate();

CREATE TRIGGER boarding_report_immutable
BEFORE UPDATE OR DELETE ON boarding_daily_reports
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();

CREATE TRIGGER boarding_contributor_immutable
BEFORE UPDATE OR DELETE ON boarding_daily_report_contributors
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();

CREATE TRIGGER boarding_submission_immutable
BEFORE UPDATE OR DELETE ON boarding_daily_report_submissions
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();

CREATE TRIGGER boarding_review_immutable
BEFORE UPDATE OR DELETE ON boarding_daily_report_reviews
FOR EACH ROW EXECUTE FUNCTION boarding_reporting_evidence_immutable();
SQL);
    }

    public function down(): void
    {
        foreach ([
            'boarding_daily_report_reviews',
            'boarding_daily_report_submissions',
            'boarding_daily_report_contributors',
            'boarding_daily_report_revisions',
            'boarding_daily_reports',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain boarding report evidence; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE boarding_daily_report_reviews;
DROP TABLE boarding_daily_report_submissions;
DROP TABLE boarding_daily_report_contributors;
DROP TABLE boarding_daily_report_revisions;
DROP TABLE boarding_daily_reports;
DROP INDEX boarding_assignment_owner_key;
DROP FUNCTION public.boarding_review_validate();
DROP FUNCTION public.boarding_submission_validate();
DROP FUNCTION public.boarding_revision_contributor();
DROP FUNCTION public.boarding_contributor_validate();
DROP FUNCTION public.boarding_revision_validate();
DROP FUNCTION public.boarding_report_actor_valid(uuid, uuid, uuid, uuid);
SQL);
    }
};
