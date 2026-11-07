<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport journey evidence requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_journey_events (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    trip_id uuid NOT NULL,
    roster_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    sequence integer NOT NULL CHECK (sequence > 0),
    previous_id uuid,
    boundary varchar(30) NOT NULL CHECK (
        boundary IN ('pickup', 'school_arrival', 'school_departure', 'destination_dropoff')
    ),
    outcome varchar(10) NOT NULL CHECK (outcome IN ('recorded', 'failed')),
    state_before varchar(30) NOT NULL,
    state_after varchar(30) NOT NULL CHECK (
        state_after IN ('expected', 'on_board', 'at_school', 'at_destination')
    ),
    crew_user_id uuid NOT NULL,
    authorization_id uuid,
    from_user_id uuid,
    from_guardian_id uuid,
    to_user_id uuid,
    to_guardian_id uuid,
    custody_user_id uuid,
    custody_guardian_id uuid,
    from_person jsonb NOT NULL,
    to_person jsonb NOT NULL,
    authorization_snapshot jsonb,
    location_snapshot jsonb NOT NULL,
    verification_method varchar(40),
    verification_note varchar(500),
    receipt_method varchar(40) NOT NULL CHECK (
        receipt_method IN ('none', 'authenticated_receiver', 'witnessed_verbal')
    ),
    receipt_state varchar(30) NOT NULL,
    confirmed_by uuid,
    failure_reason varchar(40),
    follow_up varchar(1000),
    exception_owner_id uuid,
    occurred_at timestamptz NOT NULL,
    recorded_by uuid NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    UNIQUE (roster_id, sequence),
    CHECK (num_nonnulls(from_user_id, from_guardian_id) <= 1),
    CHECK (num_nonnulls(to_user_id, to_guardian_id) <= 1),
    CHECK (num_nonnulls(custody_user_id, custody_guardian_id) <= 1),
    FOREIGN KEY (school_id, trip_id)
        REFERENCES transport_trips(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, roster_id)
        REFERENCES transport_trip_roster(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, learner_id)
        REFERENCES learners(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, previous_id)
        REFERENCES transport_journey_events(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, authorization_id)
        REFERENCES transport_handover_authorizations(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, crew_user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, from_user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, to_user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, custody_user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, from_guardian_id)
        REFERENCES parents(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, to_guardian_id)
        REFERENCES parents(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, custody_guardian_id)
        REFERENCES parents(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, recorded_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, confirmed_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, exception_owner_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE INDEX transport_journey_learner_history
ON transport_journey_events (school_id, learner_id, roster_id, sequence DESC);

CREATE OR REPLACE FUNCTION public.transport_journey_event_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    roster_row transport_trip_roster%ROWTYPE;
    trip_row transport_trips%ROWTYPE;
    previous_row transport_journey_events%ROWTYPE;
    grant_row transport_handover_authorizations%ROWTYPE;
    crew_row users%ROWTYPE;
    staff_row users%ROWTYPE;
    guardian_row parents%ROWTYPE;
    link_row learner_parents%ROWTYPE;
    required_state text;
    next_state text;
    expected_kind text;
    crew_person jsonb;
    counterpart jsonb := '{"type":"unknown"}'::jsonb;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Journey events are append-only'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO roster_row FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = NEW.roster_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school captured roster required'
            USING ERRCODE = '23503';
    END IF;

    -- Serialize actual learner custody across different captured journeys too.
    PERFORM 1 FROM learners
    WHERE school_id = NEW.school_id AND id = roster_row.learner_id
    FOR UPDATE;

    PERFORM 1 FROM transport_trip_roster
    WHERE school_id = NEW.school_id AND id = NEW.roster_id
    FOR UPDATE;

    SELECT * INTO trip_row FROM transport_trips
    WHERE school_id = NEW.school_id AND id = roster_row.trip_id;

    NEW.trip_id := roster_row.trip_id;
    NEW.learner_id := roster_row.learner_id;

    SELECT * INTO previous_row FROM transport_journey_events
    WHERE school_id = NEW.school_id AND roster_id = NEW.roster_id
    ORDER BY sequence DESC LIMIT 1;

    IF NEW.previous_id IS DISTINCT FROM previous_row.id THEN
        RAISE EXCEPTION 'Journey evidence changed; refresh before recording'
            USING ERRCODE = '23P01';
    END IF;

    NEW.sequence := COALESCE(previous_row.sequence, 0) + 1;
    NEW.state_before := COALESCE(previous_row.state_after, 'expected');

    IF trip_row.direction = 'to_school' AND NEW.boundary = 'pickup' THEN
        required_state := 'expected';
        next_state := 'on_board';
        expected_kind := 'guardian_recipient';
    ELSIF trip_row.direction = 'to_school' AND NEW.boundary = 'school_arrival' THEN
        required_state := 'on_board';
        next_state := 'at_school';
        expected_kind := 'school_receiver';
    ELSIF trip_row.direction = 'from_school' AND NEW.boundary = 'school_departure' THEN
        required_state := 'expected';
        next_state := 'on_board';
        expected_kind := 'school_releaser';
    ELSIF trip_row.direction = 'from_school' AND NEW.boundary = 'destination_dropoff' THEN
        required_state := 'on_board';
        next_state := 'at_destination';
        expected_kind := 'guardian_recipient';
    ELSE
        RAISE EXCEPTION 'Boundary does not match direction'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.state_before <> required_state THEN
        RAISE EXCEPTION 'Journey transition is out of sequence'
            USING ERRCODE = '23P01';
    END IF;

    IF NEW.occurred_at < trip_row.captured_at
        OR (previous_row.id IS NOT NULL AND NEW.occurred_at < previous_row.occurred_at) THEN
        RAISE EXCEPTION 'Event time precedes captured or prior evidence'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO crew_row FROM users
    WHERE school_id = NEW.school_id AND id = NEW.crew_user_id
    FOR SHARE;

    IF NOT FOUND OR crew_row.active IS NOT TRUE
        OR crew_row.is_deleted IS NOT FALSE
        OR crew_row.suspended_at IS NOT NULL
        OR crew_row.account_locked_until > clock_timestamp()
        OR (NEW.crew_user_id = trip_row.driver_id
            OR NEW.crew_user_id = trip_row.attendant_id) IS NOT TRUE THEN
        RAISE EXCEPTION 'Eligible captured transport crew required'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.state_before = 'on_board'
        AND NEW.crew_user_id IS DISTINCT FROM previous_row.custody_user_id THEN
        RAISE EXCEPTION 'Cannot silently change the recorded transport custodian'
            USING ERRCODE = '23514';
    END IF;

    crew_person := jsonb_build_object(
        'type', 'user', 'id', crew_row.id,
        'first_name', crew_row.first_name, 'last_name', crew_row.last_name
    );

    NEW.authorization_snapshot := NULL;
    NEW.from_user_id := NULL;
    NEW.from_guardian_id := NULL;
    NEW.to_user_id := NULL;
    NEW.to_guardian_id := NULL;
    NEW.confirmed_by := NULL;
    NEW.exception_owner_id := NULL;

    IF NEW.authorization_id IS NOT NULL THEN
        SELECT * INTO grant_row FROM transport_handover_authorizations
        WHERE school_id = NEW.school_id AND id = NEW.authorization_id
        FOR SHARE;

        IF NOT FOUND OR grant_row.assignment_id <> trip_row.assignment_id
            OR grant_row.kind <> expected_kind
            OR (expected_kind = 'guardian_recipient'
                AND (grant_row.learner_id <> roster_row.learner_id
                    OR grant_row.stop_id <> roster_row.stop_id)) THEN
            RAISE EXCEPTION 'Authorization does not match this learner and boundary'
                USING ERRCODE = '23514';
        END IF;

        NEW.authorization_snapshot := jsonb_build_object(
            'id', grant_row.id, 'identity', grant_row.identity_snapshot,
            'scope', grant_row.scope_snapshot,
            'authorized_by', grant_row.authorized_by,
            'authorized_at', grant_row.authorized_at,
            'used_for_success', NEW.outcome = 'recorded'
        );
        counterpart := grant_row.identity_snapshot || jsonb_build_object(
            'type', CASE WHEN expected_kind = 'guardian_recipient'
                THEN 'guardian' ELSE 'user' END,
            'id', COALESCE(grant_row.guardian_id, grant_row.staff_user_id)
        );
    END IF;

    -- Crew may record its own operations. School staff may record only a
    -- successful boundary for which they hold the concrete counterpart grant.
    IF (NEW.recorded_by = trip_row.driver_id
        OR NEW.recorded_by = trip_row.attendant_id) IS NOT TRUE
        AND (NEW.outcome = 'recorded'
            AND expected_kind IN ('school_receiver', 'school_releaser')
            AND NEW.recorded_by = grant_row.staff_user_id) IS NOT TRUE THEN
        RAISE EXCEPTION 'Concrete trip responsibility required'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.boundary IN ('pickup', 'school_departure') THEN
        NEW.from_person := counterpart;
        NEW.to_person := crew_person;
        NEW.from_user_id := grant_row.staff_user_id;
        NEW.from_guardian_id := grant_row.guardian_id;
        NEW.to_user_id := NEW.crew_user_id;
    ELSE
        NEW.from_person := crew_person;
        NEW.to_person := counterpart;
        NEW.from_user_id := NEW.crew_user_id;
        NEW.to_user_id := grant_row.staff_user_id;
        NEW.to_guardian_id := grant_row.guardian_id;
    END IF;

    IF NEW.boundary IN ('pickup', 'destination_dropoff') THEN
        NEW.location_snapshot := roster_row.stop_snapshot
            || jsonb_build_object('type', 'route_stop');
    ELSE
        NEW.location_snapshot := jsonb_build_object(
            'type', 'school', 'school_id', NEW.school_id,
            'service_date', trip_row.service_date
        );
    END IF;

    IF NEW.outcome = 'failed' THEN
        IF NEW.failure_reason IS NULL OR NEW.failure_reason NOT IN (
            'person_unavailable', 'person_unauthorized', 'identity_not_verified',
            'receipt_not_obtained', 'handover_refused'
        ) OR NEW.follow_up IS NULL
            OR length(btrim(NEW.follow_up)) = 0
            OR NEW.receipt_method <> 'none' THEN
            RAISE EXCEPTION 'Failed handover requires reason and continuing-custody action'
                USING ERRCODE = '23514';
        END IF;

        NEW.state_after := NEW.state_before;
        NEW.custody_user_id := previous_row.custody_user_id;
        NEW.custody_guardian_id := previous_row.custody_guardian_id;
        NEW.receipt_state := 'not_confirmed';
        NEW.exception_owner_id := NEW.recorded_by;
        -- from/to identify the attempted boundary; no transfer is asserted.
    ELSE
        IF grant_row.id IS NULL
            OR NEW.failure_reason IS NOT NULL
            OR NEW.follow_up IS NOT NULL
            OR NEW.verification_method IS NULL
            OR NEW.verification_method NOT IN (
                'known_person_in_person', 'document_checked_in_person'
            )
            OR NEW.verification_note IS NULL
            OR length(btrim(NEW.verification_note)) = 0
            OR NEW.receipt_method NOT IN ('authenticated_receiver', 'witnessed_verbal') THEN
            RAISE EXCEPTION 'Verified authorization and explicit receipt evidence required'
                USING ERRCODE = '23514';
        END IF;

        IF EXISTS (
            SELECT 1 FROM transport_handover_revocations
            WHERE school_id = NEW.school_id AND authorization_id = grant_row.id
        ) OR trip_row.service_date NOT BETWEEN grant_row.valid_from AND grant_row.valid_to
            OR NEW.occurred_at < grant_row.authorized_at THEN
            RAISE EXCEPTION 'Authorization is revoked, outside scope or issued after event'
                USING ERRCODE = '23514';
        END IF;

        IF expected_kind = 'guardian_recipient' THEN
            SELECT * INTO guardian_row FROM parents
            WHERE school_id = NEW.school_id AND id = grant_row.guardian_id
            FOR SHARE;

            SELECT * INTO link_row FROM learner_parents
            WHERE id = grant_row.guardian_link_id
                AND learner_id = roster_row.learner_id
                AND parent_id = grant_row.guardian_id
            FOR SHARE;

            IF guardian_row.id IS NULL OR guardian_row.active IS NOT TRUE
                OR guardian_row.is_deleted IS NOT FALSE
                OR link_row.id IS NULL OR link_row.active IS NOT TRUE
                OR link_row.is_deleted IS NOT FALSE
                OR link_row.can_pick_learner IS NOT TRUE THEN
                RAISE EXCEPTION 'Guardian collection eligibility is no longer current'
                    USING ERRCODE = '23514';
            END IF;
        ELSE
            SELECT * INTO staff_row FROM users
            WHERE school_id = NEW.school_id AND id = grant_row.staff_user_id
            FOR SHARE;

            IF NOT FOUND OR staff_row.active IS NOT TRUE
                OR staff_row.is_deleted IS NOT FALSE
                OR staff_row.suspended_at IS NOT NULL
                OR staff_row.account_locked_until > clock_timestamp() THEN
                RAISE EXCEPTION 'School counterpart is no longer eligible'
                    USING ERRCODE = '23514';
            END IF;
        END IF;

        IF NEW.receipt_method = 'authenticated_receiver' THEN
            IF NEW.to_user_id IS DISTINCT FROM NEW.recorded_by THEN
                RAISE EXCEPTION 'Only the actual receiving User can authenticate receipt'
                    USING ERRCODE = '23514';
            END IF;
            NEW.confirmed_by := NEW.recorded_by;
            NEW.receipt_state := 'authenticated';
        ELSE
            NEW.receipt_state := 'verbally_reported';
        END IF;

        NEW.state_after := next_state;
        NEW.custody_user_id := NEW.to_user_id;
        NEW.custody_guardian_id := NEW.to_guardian_id;

        IF next_state = 'on_board' AND EXISTS (
            SELECT 1 FROM (
                SELECT DISTINCT ON (roster_id) roster_id, state_after
                FROM transport_journey_events
                WHERE school_id = NEW.school_id AND learner_id = NEW.learner_id
                ORDER BY roster_id, sequence DESC
            ) latest
            WHERE latest.roster_id <> NEW.roster_id AND latest.state_after = 'on_board'
        ) THEN
            RAISE EXCEPTION 'Learner remains in custody on another journey'
                USING ERRCODE = '23P01';
        END IF;
    END IF;

    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_journey_event_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_journey_events
FOR EACH ROW EXECUTE FUNCTION public.transport_journey_event_validate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('transport_journey_events')->exists()) {
            throw new RuntimeException(
                'Retain journey security evidence; use a reviewed forward migration.'
            );
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_journey_events;
DROP FUNCTION public.transport_journey_event_validate();
SQL);
    }
};
