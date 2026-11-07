<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport exception evidence requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_exception_follow_ups (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    failure_event_id uuid NOT NULL,
    roster_id uuid NOT NULL,
    sequence integer NOT NULL CHECK (sequence > 0),
    previous_id uuid,
    basis_event_id uuid NOT NULL,
    action varchar(20) NOT NULL CHECK (action IN ('follow_up', 'resolve')),
    note varchar(1000) NOT NULL CHECK (length(btrim(note)) BETWEEN 1 AND 1000),
    assigned_to uuid NOT NULL,
    resolution_event_id uuid,
    custody_snapshot jsonb NOT NULL,
    recorded_by uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    UNIQUE (failure_event_id, sequence),
    CHECK (
        (action = 'resolve' AND resolution_event_id IS NOT NULL)
        OR (action = 'follow_up' AND resolution_event_id IS NULL)
    ),
    FOREIGN KEY (school_id, failure_event_id)
        REFERENCES transport_journey_events(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, basis_event_id)
        REFERENCES transport_journey_events(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, resolution_event_id)
        REFERENCES transport_journey_events(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, roster_id)
        REFERENCES transport_trip_roster(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, previous_id)
        REFERENCES transport_exception_follow_ups(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, assigned_to)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, recorded_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE INDEX transport_exception_history
ON transport_exception_follow_ups (school_id, failure_event_id, sequence DESC);

CREATE UNIQUE INDEX transport_exception_one_resolution
ON transport_exception_follow_ups (failure_event_id)
WHERE action = 'resolve';

CREATE OR REPLACE FUNCTION public.transport_exception_follow_up_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    failure_row transport_journey_events%ROWTYPE;
    latest_event transport_journey_events%ROWTYPE;
    proof_row transport_journey_events%ROWTYPE;
    previous_row transport_exception_follow_ups%ROWTYPE;
    owner_row users%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Exception follow-up history is append-only'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO failure_row FROM transport_journey_events
    WHERE school_id = NEW.school_id AND id = NEW.failure_event_id;

    IF NOT FOUND OR failure_row.outcome <> 'failed' THEN
        RAISE EXCEPTION 'Same-school failed handover required'
            USING ERRCODE = '23514';
    END IF;

    -- Match the journey-event custody lock order.
    PERFORM 1 FROM learners
    WHERE school_id = NEW.school_id AND id = failure_row.learner_id
    FOR UPDATE;

    PERFORM 1 FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = failure_row.roster_id
    FOR UPDATE;

    PERFORM 1 FROM transport_journey_events
    WHERE school_id = NEW.school_id AND id = failure_row.id
    FOR UPDATE;

    NEW.roster_id := failure_row.roster_id;

    SELECT * INTO latest_event FROM transport_journey_events
    WHERE school_id = NEW.school_id AND roster_id = failure_row.roster_id
    ORDER BY sequence DESC LIMIT 1;

    IF NEW.basis_event_id IS DISTINCT FROM latest_event.id THEN
        RAISE EXCEPTION 'Journey changed before exception action'
            USING ERRCODE = '23P01';
    END IF;

    SELECT * INTO previous_row FROM transport_exception_follow_ups
    WHERE school_id = NEW.school_id AND failure_event_id = failure_row.id
    ORDER BY sequence DESC LIMIT 1;

    IF NEW.previous_id IS DISTINCT FROM previous_row.id THEN
        RAISE EXCEPTION 'Exception history changed'
            USING ERRCODE = '23P01';
    END IF;

    IF previous_row.action = 'resolve' THEN
        RAISE EXCEPTION 'Resolved exception history cannot be extended'
            USING ERRCODE = '23P01';
    END IF;

    NEW.sequence := COALESCE(previous_row.sequence, 0) + 1;

    SELECT * INTO owner_row FROM users
    WHERE school_id = NEW.school_id AND id = NEW.assigned_to
    FOR SHARE;

    IF NOT FOUND OR owner_row.active IS NOT TRUE
        OR owner_row.is_deleted IS NOT FALSE
        OR owner_row.suspended_at IS NOT NULL
        OR owner_row.account_locked_until > clock_timestamp() THEN
        RAISE EXCEPTION 'Eligible same-school exception owner required'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.action = 'resolve' THEN
        SELECT * INTO proof_row FROM transport_journey_events
        WHERE school_id = NEW.school_id AND id = NEW.resolution_event_id;

        IF NOT FOUND OR proof_row.outcome <> 'recorded'
            OR proof_row.roster_id <> failure_row.roster_id
            OR proof_row.learner_id <> failure_row.learner_id
            OR proof_row.boundary <> failure_row.boundary
            OR proof_row.sequence <= failure_row.sequence
            OR proof_row.occurred_at < failure_row.occurred_at
            OR proof_row.receipt_state NOT IN ('authenticated', 'verbally_reported') THEN
            RAISE EXCEPTION 'Later successful handover at the failed boundary required'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    NEW.custody_snapshot := jsonb_build_object(
        'basis_event_id', latest_event.id,
        'state', latest_event.state_after,
        'custody_user_id', latest_event.custody_user_id,
        'custody_guardian_id', latest_event.custody_guardian_id,
        'custodian_identified',
            num_nonnulls(latest_event.custody_user_id, latest_event.custody_guardian_id) = 1,
        'resolution_receipt_state',
            CASE WHEN NEW.action = 'resolve' THEN proof_row.receipt_state ELSE NULL END
    );
    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_exception_follow_up_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_exception_follow_ups
FOR EACH ROW EXECUTE FUNCTION public.transport_exception_follow_up_validate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('transport_exception_follow_ups')->exists()) {
            throw new RuntimeException(
                'Retain exception follow-up evidence; use a reviewed forward migration.'
            );
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_exception_follow_ups;
DROP FUNCTION public.transport_exception_follow_up_validate();
SQL);
    }
};
