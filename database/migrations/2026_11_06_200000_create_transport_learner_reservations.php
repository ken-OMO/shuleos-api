<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport reservations require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS transport_learner_owner_key
ON learners (school_id, id);

CREATE TABLE transport_learner_reservations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    assignment_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    stop_id uuid NOT NULL,
    service_date date NOT NULL,
    direction varchar(20) NOT NULL
        CHECK (direction IN ('to_school', 'from_school')),
    starts_at timestamptz NOT NULL,
    completes_at timestamptz NOT NULL,
    seat_slot integer NOT NULL CHECK (seat_slot > 0),
    active boolean NOT NULL DEFAULT true,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    FOREIGN KEY (school_id, assignment_id)
        REFERENCES transport_dated_assignments(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, learner_id)
        REFERENCES learners(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, stop_id)
        REFERENCES transport_route_stops(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CHECK (completes_at > starts_at),
    CONSTRAINT transport_learner_journey_overlap
        EXCLUDE USING gist (
            school_id WITH =,
            learner_id WITH =,
            tstzrange(starts_at, completes_at, '[)') WITH &&
        ) WHERE (active)
);

CREATE UNIQUE INDEX transport_reservation_active_seat_key
ON transport_learner_reservations (assignment_id, seat_slot) WHERE active;

CREATE UNIQUE INDEX transport_reservation_active_learner_key
ON transport_learner_reservations (assignment_id, learner_id) WHERE active;

CREATE OR REPLACE FUNCTION public.transport_learner_reservation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    assignment_row transport_dated_assignments%ROWTYPE;
    schedule_row transport_service_schedules%ROWTYPE;
    capacity integer;
    selected_slot integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retain transport learner reservation history'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF (to_jsonb(NEW) - ARRAY['active','lock_version','updated_by','updated_at'])
           IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['active','lock_version','updated_by','updated_at']) THEN
            RAISE EXCEPTION 'Learner reservation identity is immutable'
                USING ERRCODE = '23514';
        END IF;

        IF NOT OLD.active AND NEW.active THEN
            RAISE EXCEPTION 'Ended reservations cannot be reactivated'
                USING ERRCODE = '23514';
        END IF;

        NEW.lock_version := OLD.lock_version + 1;
        NEW.updated_at := clock_timestamp();
        RETURN NEW;
    END IF;

    IF NOT NEW.active OR NEW.lock_version <> 1
       OR NEW.created_by <> NEW.updated_by THEN
        RAISE EXCEPTION 'Invalid initial learner reservation'
            USING ERRCODE = '23514';
    END IF;

    -- This row lock serializes contenders for the same service run.
    SELECT * INTO assignment_row
    FROM transport_dated_assignments
    WHERE school_id = NEW.school_id AND id = NEW.assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school assignment required'
            USING ERRCODE = '23503';
    END IF;

    IF NOT assignment_row.active THEN
        RAISE EXCEPTION 'Assignment has ended'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row
    FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = assignment_row.schedule_id;

    IF NOT FOUND OR NOT schedule_row.active THEN
        RAISE EXCEPTION 'Active schedule required'
            USING ERRCODE = '23514';
    END IF;

    SELECT learner_capacity INTO capacity
    FROM transport_vehicles
    WHERE school_id = NEW.school_id AND id = assignment_row.vehicle_id AND active
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Active assigned vehicle required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM transport_routes
        WHERE school_id = NEW.school_id AND id = schedule_row.route_id AND active
    ) THEN
        RAISE EXCEPTION 'Active route required'
            USING ERRCODE = '23514';
    END IF;

    PERFORM 1 FROM learners
    WHERE school_id = NEW.school_id AND id = NEW.learner_id
      AND active IS TRUE AND is_deleted IS FALSE
      AND mode_of_study = 'day_scholar'
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Eligible same-school day scholar required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM transport_route_stops
        WHERE school_id = NEW.school_id AND id = NEW.stop_id
          AND route_id = schedule_row.route_id
          AND direction = schedule_row.direction AND active
    ) THEN
        RAISE EXCEPTION 'Active stop must match service route and direction'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1 FROM transport_learner_reservations
        WHERE assignment_id = NEW.assignment_id
          AND learner_id = NEW.learner_id AND active
    ) THEN
        RAISE EXCEPTION 'Learner already reserved on this service run'
            USING ERRCODE = '23505';
    END IF;

    -- Find the lowest free internal slot without generating capacity-sized sets.
    SELECT min(candidate)::integer INTO selected_slot
    FROM (
        SELECT 1::bigint AS candidate
        UNION
        SELECT seat_slot::bigint + 1
        FROM transport_learner_reservations
        WHERE assignment_id = NEW.assignment_id AND active
    ) candidates
    WHERE candidate <= capacity
      AND NOT EXISTS (
          SELECT 1 FROM transport_learner_reservations occupied
          WHERE occupied.assignment_id = NEW.assignment_id
            AND occupied.active AND occupied.seat_slot = candidate
      );

    IF selected_slot IS NULL THEN
        RAISE EXCEPTION 'No learner capacity remains on this service run'
            USING ERRCODE = '23P01';
    END IF;

    NEW.seat_slot := selected_slot;
    NEW.service_date := assignment_row.service_date;
    NEW.direction := schedule_row.direction;
    NEW.starts_at := assignment_row.starts_at;
    NEW.completes_at := assignment_row.completes_at;
    NEW.created_at := clock_timestamp();
    NEW.updated_at := NEW.created_at;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_learner_reservation_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_learner_reservations
FOR EACH ROW EXECUTE FUNCTION public.transport_learner_reservation_validate();

CREATE OR REPLACE FUNCTION public.transport_reserved_capacity_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.learner_capacity < OLD.learner_capacity AND EXISTS (
        SELECT 1
        FROM transport_learner_reservations reservation
        JOIN transport_dated_assignments assignment
          ON assignment.id = reservation.assignment_id
         AND assignment.school_id = reservation.school_id
        WHERE assignment.school_id = NEW.school_id
          AND assignment.vehicle_id = NEW.id
          AND reservation.active
          AND reservation.seat_slot > NEW.learner_capacity
    ) THEN
        RAISE EXCEPTION 'Vehicle capacity is reserved; release or transfer reservations first'
            USING ERRCODE = '23P01';
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_vehicle_reserved_capacity_guard
BEFORE UPDATE ON transport_vehicles
FOR EACH ROW EXECUTE FUNCTION public.transport_reserved_capacity_guard();

CREATE OR REPLACE FUNCTION public.transport_assignment_reservation_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.active AND NOT NEW.active AND EXISTS (
        SELECT 1 FROM transport_learner_reservations
        WHERE school_id = OLD.school_id AND assignment_id = OLD.id AND active
    ) THEN
        RAISE EXCEPTION 'Release or transfer learner reservations before ending assignment'
            USING ERRCODE = '23P01';
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_assignment_reservation_guard
BEFORE UPDATE ON transport_dated_assignments
FOR EACH ROW EXECUTE FUNCTION public.transport_assignment_reservation_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('transport_learner_reservations')->exists()) {
            throw new RuntimeException(
                'Retain learner reservations; use a reviewed forward migration.'
            );
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER transport_assignment_reservation_guard ON transport_dated_assignments;
DROP TRIGGER transport_vehicle_reserved_capacity_guard ON transport_vehicles;
DROP TABLE transport_learner_reservations;
DROP FUNCTION public.transport_assignment_reservation_guard();
DROP FUNCTION public.transport_reserved_capacity_guard();
DROP FUNCTION public.transport_learner_reservation_validate();
SQL);
        // Retain the tenant key index, which other migrations may reuse.
    }
};
