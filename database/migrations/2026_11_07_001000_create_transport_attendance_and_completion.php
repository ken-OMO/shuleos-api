<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport attendance requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_journey_outcomes (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    trip_id uuid NOT NULL,
    roster_id uuid NOT NULL UNIQUE,
    outcome varchar(30) NOT NULL CHECK (
        outcome IN ('missed_pickup', 'not_boarded', 'cancelled')
    ),
    reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 1000),
    recorded_by uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    FOREIGN KEY (school_id, trip_id)
        REFERENCES transport_trips(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, roster_id)
        REFERENCES transport_trip_roster(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, recorded_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE TABLE transport_trip_completions (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    trip_id uuid NOT NULL,
    sequence integer NOT NULL CHECK (sequence > 0),
    previous_id uuid,
    status varchar(20) NOT NULL CHECK (status IN ('completed', 'incomplete')),
    reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 1000),
    snapshot jsonb NOT NULL,
    recorded_by uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    UNIQUE (trip_id, sequence),
    FOREIGN KEY (school_id, trip_id)
        REFERENCES transport_trips(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, previous_id)
        REFERENCES transport_trip_completions(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, recorded_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE OR REPLACE FUNCTION public.transport_trip_attendance_snapshot(p_school uuid, p_trip uuid)
RETURNS jsonb LANGUAGE sql STABLE AS $$
WITH states AS (
    SELECT r.id, r.learner_id, e.id AS event_id, o.id AS outcome_id,
        COALESCE(o.outcome, e.state_after, 'expected') AS state
    FROM transport_trip_roster r
    LEFT JOIN LATERAL (
        SELECT id, state_after FROM transport_journey_events
        WHERE school_id = p_school AND roster_id = r.id
        ORDER BY sequence DESC LIMIT 1
    ) e ON true
    LEFT JOIN transport_journey_outcomes o
        ON o.school_id = p_school AND o.roster_id = r.id
    WHERE r.school_id = p_school AND r.trip_id = p_trip
), failures AS (
    SELECT e.id, f.id AS follow_up_id, COALESCE(f.action = 'resolve', false) AS resolved
    FROM transport_journey_events e
    LEFT JOIN LATERAL (
        SELECT id, action FROM transport_exception_follow_ups
        WHERE school_id = p_school AND failure_event_id = e.id
        ORDER BY sequence DESC LIMIT 1
    ) f ON true
    WHERE e.school_id = p_school AND e.trip_id = p_trip AND e.outcome = 'failed'
)
SELECT jsonb_build_object(
    'planned_legs', COUNT(*),
    'awaiting_outcome', COUNT(*) FILTER (WHERE state = 'expected'),
    'on_board', COUNT(*) FILTER (WHERE state = 'on_board'),
    'handed_over', COUNT(*) FILTER (WHERE state IN ('at_school', 'at_destination')),
    'missed_pickup', COUNT(*) FILTER (WHERE state = 'missed_pickup'),
    'not_boarded', COUNT(*) FILTER (WHERE state = 'not_boarded'),
    'cancelled', COUNT(*) FILTER (WHERE state = 'cancelled'),
    'transported_legs', COUNT(*) FILTER (
        WHERE state IN ('on_board', 'at_school', 'at_destination')
    ),
    'unique_learners_transported', COUNT(DISTINCT learner_id) FILTER (
        WHERE state IN ('on_board', 'at_school', 'at_destination')
    ),
    'onboard_roster_ids', COALESCE(
        jsonb_agg(id ORDER BY id) FILTER (WHERE state = 'on_board'), '[]'::jsonb
    ),
    'awaiting_roster_ids', COALESCE(
        jsonb_agg(id ORDER BY id) FILTER (WHERE state = 'expected'), '[]'::jsonb
    ),
    'journey_basis', COALESCE(
        jsonb_agg(jsonb_build_object(
            'roster_id', id, 'event_id', event_id, 'outcome_id', outcome_id
        ) ORDER BY id), '[]'::jsonb
    ),
    'unresolved_exceptions', (SELECT COUNT(*) FROM failures WHERE NOT resolved),
    'unresolved_failure_ids', (
        SELECT COALESCE(jsonb_agg(id ORDER BY id), '[]'::jsonb)
        FROM failures WHERE NOT resolved
    ),
    'exception_basis', (
        SELECT COALESCE(jsonb_agg(jsonb_build_object(
            'failure_id', id, 'follow_up_id', follow_up_id, 'resolved', resolved
        ) ORDER BY id), '[]'::jsonb) FROM failures
    )
) FROM states;
$$;

CREATE OR REPLACE FUNCTION public.transport_attendance_event_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_trip uuid;
BEGIN
    SELECT trip_id INTO v_trip FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = NEW.roster_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school captured roster required'
            USING ERRCODE = '23503';
    END IF;

    PERFORM 1 FROM transport_trips
    WHERE school_id = NEW.school_id AND id = v_trip
    FOR UPDATE;

    IF EXISTS (
        SELECT 1 FROM transport_trip_completions
        WHERE school_id = NEW.school_id AND trip_id = v_trip AND status = 'completed'
    ) OR EXISTS (
        SELECT 1 FROM transport_journey_outcomes
        WHERE school_id = NEW.school_id AND roster_id = NEW.roster_id
    ) THEN
        RAISE EXCEPTION 'Completed trip or terminal non-travel outcome prevents new events'
            USING ERRCODE = '23P01';
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_attendance_event_guard
BEFORE INSERT ON transport_journey_events
FOR EACH ROW EXECUTE FUNCTION public.transport_attendance_event_guard();

CREATE OR REPLACE FUNCTION public.transport_journey_outcome_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    roster_row transport_trip_roster%ROWTYPE;
    trip_row transport_trips%ROWTYPE;
    v_state text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Attendance outcomes are immutable'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO roster_row FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = NEW.roster_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school roster required' USING ERRCODE = '23503';
    END IF;

    SELECT * INTO trip_row FROM transport_trips
    WHERE school_id = NEW.school_id AND id = roster_row.trip_id
    FOR UPDATE;

    PERFORM 1 FROM learners
    WHERE school_id = NEW.school_id AND id = roster_row.learner_id
    FOR UPDATE;
    PERFORM 1 FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = roster_row.id
    FOR UPDATE;

    IF EXISTS (
        SELECT 1 FROM transport_trip_completions
        WHERE school_id = NEW.school_id AND trip_id = trip_row.id AND status = 'completed'
    ) THEN
        RAISE EXCEPTION 'Trip is already completed' USING ERRCODE = '23P01';
    END IF;

    IF (NEW.recorded_by = trip_row.driver_id
        OR NEW.recorded_by = trip_row.attendant_id) IS NOT TRUE THEN
        RAISE EXCEPTION 'Captured crew responsibility required' USING ERRCODE = '23514';
    END IF;

    SELECT state_after INTO v_state FROM transport_journey_events
    WHERE school_id = NEW.school_id AND roster_id = NEW.roster_id
    ORDER BY sequence DESC LIMIT 1;

    IF COALESCE(v_state, 'expected') <> 'expected' THEN
        RAISE EXCEPTION 'Cannot cancel or mark non-travel after boarding'
            USING ERRCODE = '23P01';
    END IF;

    IF (NEW.outcome = 'missed_pickup' AND trip_row.direction <> 'to_school')
        OR (NEW.outcome = 'not_boarded' AND trip_row.direction <> 'from_school') THEN
        RAISE EXCEPTION 'Attendance outcome does not match service direction'
            USING ERRCODE = '23514';
    END IF;

    NEW.trip_id := trip_row.id;
    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_journey_outcome_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_journey_outcomes
FOR EACH ROW EXECUTE FUNCTION public.transport_journey_outcome_validate();

CREATE OR REPLACE FUNCTION public.transport_trip_completion_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    trip_row transport_trips%ROWTYPE;
    previous_row transport_trip_completions%ROWTYPE;
    current_snapshot jsonb;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Completion attempts are immutable' USING ERRCODE = '23514';
    END IF;

    SELECT * INTO trip_row FROM transport_trips
    WHERE school_id = NEW.school_id AND id = NEW.trip_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school captured trip required' USING ERRCODE = '23503';
    END IF;

    IF (NEW.recorded_by = trip_row.driver_id
        OR NEW.recorded_by = trip_row.attendant_id) IS NOT TRUE THEN
        RAISE EXCEPTION 'Captured crew responsibility required' USING ERRCODE = '23514';
    END IF;

    SELECT * INTO previous_row FROM transport_trip_completions
    WHERE school_id = NEW.school_id AND trip_id = NEW.trip_id
    ORDER BY sequence DESC LIMIT 1;

    IF previous_row.status = 'completed'
        OR NEW.previous_id IS DISTINCT FROM previous_row.id THEN
        RAISE EXCEPTION 'Trip completion state changed' USING ERRCODE = '23P01';
    END IF;

    current_snapshot := public.transport_trip_attendance_snapshot(NEW.school_id, NEW.trip_id);
    IF NEW.snapshot IS DISTINCT FROM current_snapshot THEN
        RAISE EXCEPTION 'Attendance or exception evidence changed'
            USING ERRCODE = '23P01';
    END IF;

    NEW.sequence := COALESCE(previous_row.sequence, 0) + 1;
    NEW.status := CASE
        WHEN (current_snapshot->>'awaiting_outcome')::integer = 0
            AND (current_snapshot->>'on_board')::integer = 0
            AND (current_snapshot->>'unresolved_exceptions')::integer = 0
        THEN 'completed' ELSE 'incomplete' END;
    NEW.snapshot := current_snapshot;
    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_trip_completion_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_trip_completions
FOR EACH ROW EXECUTE FUNCTION public.transport_trip_completion_validate();
SQL);
    }

    public function down(): void
    {
        foreach (['transport_journey_outcomes', 'transport_trip_completions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain attendance and completion history; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER transport_attendance_event_guard ON transport_journey_events;
DROP TABLE transport_trip_completions;
DROP TABLE transport_journey_outcomes;
DROP FUNCTION public.transport_trip_completion_validate();
DROP FUNCTION public.transport_journey_outcome_validate();
DROP FUNCTION public.transport_attendance_event_guard();
DROP FUNCTION public.transport_trip_attendance_snapshot(uuid, uuid);
SQL);
    }
};
