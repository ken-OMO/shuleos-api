<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport handovers require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        // Retained on rollback: existing guardian ownership is unchanged.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS transport_parent_owner_key ON parents (school_id, id)'
        );

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_handover_authorizations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    assignment_id uuid NOT NULL,
    kind varchar(30) NOT NULL
        CHECK (kind IN ('guardian_recipient', 'school_receiver', 'school_releaser')),
    learner_id uuid,
    stop_id uuid,
    guardian_id uuid,
    guardian_link_id uuid REFERENCES learner_parents(id) ON DELETE RESTRICT,
    staff_user_id uuid,
    valid_from date NOT NULL CHECK (isfinite(valid_from)),
    valid_to date NOT NULL CHECK (isfinite(valid_to)),
    reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 1000),
    identity_snapshot jsonb NOT NULL,
    scope_snapshot jsonb NOT NULL,
    authorized_by uuid NOT NULL,
    authorized_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    CHECK (valid_to >= valid_from AND valid_to - valid_from <= 365),
    CHECK (
        (kind = 'guardian_recipient'
            AND learner_id IS NOT NULL AND stop_id IS NOT NULL
            AND guardian_id IS NOT NULL AND guardian_link_id IS NOT NULL
            AND staff_user_id IS NULL)
        OR
        (kind IN ('school_receiver', 'school_releaser')
            AND learner_id IS NULL AND stop_id IS NULL
            AND guardian_id IS NULL AND guardian_link_id IS NULL
            AND staff_user_id IS NOT NULL)
    ),
    FOREIGN KEY (school_id, assignment_id)
        REFERENCES transport_dated_assignments(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, learner_id)
        REFERENCES learners(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, stop_id)
        REFERENCES transport_route_stops(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, guardian_id)
        REFERENCES parents(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, staff_user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, authorized_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE INDEX transport_handover_assignment_scope
ON transport_handover_authorizations (school_id, assignment_id, kind);

CREATE TABLE transport_handover_revocations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    authorization_id uuid NOT NULL UNIQUE,
    reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 1000),
    revoked_by uuid NOT NULL,
    revoked_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    FOREIGN KEY (school_id, authorization_id)
        REFERENCES transport_handover_authorizations(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, revoked_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE OR REPLACE FUNCTION public.transport_handover_authorization_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    assignment_row record;
    schedule_row record;
    person_row record;
    link_row record;
    stop_row record;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Handover authorization evidence is immutable'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO assignment_row
    FROM transport_dated_assignments
    WHERE school_id = NEW.school_id AND id = NEW.assignment_id
    FOR UPDATE;

    IF NOT FOUND OR assignment_row.active IS NOT TRUE THEN
        RAISE EXCEPTION 'Active same-school assignment required'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row
    FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = assignment_row.schedule_id
    FOR SHARE;

    IF NOT FOUND OR schedule_row.active IS NOT TRUE THEN
        RAISE EXCEPTION 'Active same-school schedule required'
            USING ERRCODE = '23514';
    END IF;

    IF assignment_row.service_date NOT BETWEEN NEW.valid_from AND NEW.valid_to THEN
        RAISE EXCEPTION 'Authorization dates must include the concrete service date'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.kind = 'guardian_recipient' THEN
        SELECT * INTO person_row FROM parents
        WHERE school_id = NEW.school_id AND id = NEW.guardian_id
        FOR SHARE;

        IF NOT FOUND OR person_row.active IS NOT TRUE
            OR person_row.is_deleted IS NOT FALSE THEN
            RAISE EXCEPTION 'Active same-school guardian required'
                USING ERRCODE = '23514';
        END IF;

        SELECT * INTO link_row FROM learner_parents
        WHERE id = NEW.guardian_link_id
            AND learner_id = NEW.learner_id AND parent_id = NEW.guardian_id
        FOR SHARE;

        IF NOT FOUND OR link_row.active IS NOT TRUE
            OR link_row.is_deleted IS NOT FALSE
            OR link_row.can_pick_learner IS NOT TRUE THEN
            RAISE EXCEPTION 'Current guardian link with collection eligibility required'
                USING ERRCODE = '23514';
        END IF;

        PERFORM 1 FROM learners
        WHERE school_id = NEW.school_id AND id = NEW.learner_id
            AND active IS TRUE AND is_deleted IS FALSE
            AND mode_of_study = 'day_scholar'
        FOR SHARE;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Eligible same-school day learner required'
                USING ERRCODE = '23514';
        END IF;

        SELECT * INTO stop_row FROM transport_route_stops
        WHERE school_id = NEW.school_id AND id = NEW.stop_id
            AND route_id = schedule_row.route_id
            AND direction = schedule_row.direction AND active IS TRUE
        FOR SHARE;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Active stop must match service route and direction'
                USING ERRCODE = '23514';
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM transport_learner_reservations
            WHERE school_id = NEW.school_id AND assignment_id = NEW.assignment_id
                AND learner_id = NEW.learner_id AND stop_id = NEW.stop_id
                AND active IS TRUE
        ) THEN
            RAISE EXCEPTION 'Matching active learner reservation required'
                USING ERRCODE = '23514';
        END IF;

        NEW.identity_snapshot := jsonb_build_object(
            'guardian_id', person_row.id,
            'first_name', person_row.first_name,
            'last_name', person_row.last_name,
            'guardian_link_id', link_row.id,
            'relationship', link_row.relationship,
            'can_pick_learner', link_row.can_pick_learner
        );
    ELSE
        IF (NEW.kind = 'school_receiver' AND schedule_row.direction <> 'to_school')
            OR (NEW.kind = 'school_releaser' AND schedule_row.direction <> 'from_school') THEN
            RAISE EXCEPTION 'School handover function must match service direction'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.staff_user_id = assignment_row.driver_id
            OR NEW.staff_user_id = assignment_row.attendant_id THEN
            RAISE EXCEPTION 'School counterpart must be distinct from transport crew'
                USING ERRCODE = '23514';
        END IF;

        SELECT * INTO person_row FROM users
        WHERE school_id = NEW.school_id AND id = NEW.staff_user_id
        FOR SHARE;

        IF NOT FOUND OR person_row.active IS NOT TRUE
            OR person_row.is_deleted IS NOT FALSE
            OR person_row.suspended_at IS NOT NULL
            OR person_row.account_locked_until > clock_timestamp() THEN
            RAISE EXCEPTION 'Eligible same-school receiving or releasing user required'
                USING ERRCODE = '23514';
        END IF;

        NEW.identity_snapshot := jsonb_build_object(
            'user_id', person_row.id,
            'first_name', person_row.first_name,
            'last_name', person_row.last_name
        );
    END IF;

    NEW.scope_snapshot := jsonb_build_object(
        'assignment_id', assignment_row.id,
        'service_date', assignment_row.service_date,
        'schedule_id', assignment_row.schedule_id,
        'run_number', assignment_row.run_number,
        'direction', schedule_row.direction,
        'route_id', schedule_row.route_id,
        'timezone', schedule_row.timezone,
        'learner_id', NEW.learner_id,
        'stop_id', NEW.stop_id,
        'kind', NEW.kind,
        'valid_from', NEW.valid_from,
        'valid_to', NEW.valid_to
    );
    NEW.authorized_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_handover_revocation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Handover revocation evidence is immutable'
            USING ERRCODE = '23514';
    END IF;

    PERFORM 1 FROM transport_handover_authorizations
    WHERE school_id = NEW.school_id AND id = NEW.authorization_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school authorization required'
            USING ERRCODE = '23503';
    END IF;

    NEW.revoked_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_handover_authorization_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_handover_authorizations
FOR EACH ROW EXECUTE FUNCTION public.transport_handover_authorization_validate();

CREATE TRIGGER transport_handover_revocation_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_handover_revocations
FOR EACH ROW EXECUTE FUNCTION public.transport_handover_revocation_validate();
SQL);
    }

    public function down(): void
    {
        foreach ([
            'transport_handover_revocations',
            'transport_handover_authorizations',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain handover authorization history; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_handover_revocations;
DROP TABLE transport_handover_authorizations;
DROP FUNCTION public.transport_handover_revocation_validate();
DROP FUNCTION public.transport_handover_authorization_validate();
SQL);
    }
};
