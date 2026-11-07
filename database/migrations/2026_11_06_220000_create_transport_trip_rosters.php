<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport trip capture requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_trips (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    assignment_id uuid NOT NULL UNIQUE,
    service_date date NOT NULL,
    direction varchar(20) NOT NULL
        CHECK (direction IN ('to_school', 'from_school')),
    driver_id uuid NOT NULL,
    attendant_id uuid,
    context_snapshot jsonb NOT NULL,
    staff_snapshot jsonb NOT NULL,
    roster_count integer NOT NULL CHECK (roster_count >= 0),
    captured_by uuid NOT NULL,
    captured_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    FOREIGN KEY (school_id, assignment_id)
        REFERENCES transport_dated_assignments(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, driver_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, attendant_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, captured_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE TABLE transport_trip_roster (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    trip_id uuid NOT NULL,
    reservation_id uuid NOT NULL UNIQUE,
    learner_id uuid NOT NULL,
    stop_id uuid NOT NULL,
    learner_snapshot jsonb NOT NULL,
    stop_snapshot jsonb NOT NULL,
    reservation_snapshot jsonb NOT NULL,
    UNIQUE (school_id, id),
    UNIQUE (trip_id, learner_id),
    FOREIGN KEY (school_id, trip_id)
        REFERENCES transport_trips(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, reservation_id)
        REFERENCES transport_learner_reservations(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, learner_id)
        REFERENCES learners(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, stop_id)
        REFERENCES transport_route_stops(school_id, id) ON DELETE RESTRICT
);

CREATE OR REPLACE FUNCTION public.transport_trip_capture_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    assignment_row transport_dated_assignments%ROWTYPE;
    schedule_row transport_service_schedules%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Captured trip evidence is immutable'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO assignment_row
    FROM transport_dated_assignments
    WHERE school_id = NEW.school_id AND id = NEW.assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school assignment required'
            USING ERRCODE = '23503';
    END IF;

    IF NOT assignment_row.active
       OR (
           NEW.captured_by = assignment_row.driver_id
           OR NEW.captured_by = assignment_row.attendant_id
       ) IS NOT TRUE THEN
        RAISE EXCEPTION 'Active assigned driver or attendant must capture trip'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row
    FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = assignment_row.schedule_id;

    IF NOT FOUND OR NOT schedule_row.active THEN
        RAISE EXCEPTION 'Active service schedule required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM transport_vehicles
        WHERE school_id = NEW.school_id AND id = assignment_row.vehicle_id AND active
    ) OR NOT EXISTS (
        SELECT 1 FROM transport_routes
        WHERE school_id = NEW.school_id AND id = schedule_row.route_id AND active
    ) THEN
        RAISE EXCEPTION 'Active vehicle and route required'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1 FROM users
        WHERE school_id = NEW.school_id
          AND (id = assignment_row.driver_id OR id = assignment_row.attendant_id)
          AND (
              active IS NOT TRUE OR is_deleted IS NOT FALSE
              OR suspended_at IS NOT NULL
              OR account_locked_until > clock_timestamp()
          )
    ) THEN
        RAISE EXCEPTION 'Assigned staff must remain eligible at capture'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1 FROM transport_learner_reservations reservation
        JOIN learners learner
          ON learner.school_id = reservation.school_id
         AND learner.id = reservation.learner_id
        JOIN transport_route_stops stop
          ON stop.school_id = reservation.school_id
         AND stop.id = reservation.stop_id
        WHERE reservation.school_id = NEW.school_id
          AND reservation.assignment_id = NEW.assignment_id AND reservation.active
          AND (
              learner.active IS NOT TRUE OR learner.is_deleted IS NOT FALSE
              OR learner.mode_of_study IS DISTINCT FROM 'day_scholar'
              OR NOT stop.active
          )
    ) THEN
        RAISE EXCEPTION 'Resolve ineligible learner or stop reservations before capture'
            USING ERRCODE = '23514';
    END IF;

    NEW.service_date := assignment_row.service_date;
    NEW.direction := schedule_row.direction;
    NEW.driver_id := assignment_row.driver_id;
    NEW.attendant_id := assignment_row.attendant_id;
    NEW.captured_at := clock_timestamp();

    SELECT jsonb_build_object(
        'schedule_id', schedule_row.id,
        'service_code', schedule_row.service_code,
        'direction', schedule_row.direction,
        'timezone', schedule_row.timezone,
        'run_number', assignment_row.run_number,
        'service_date', assignment_row.service_date,
        'starts_at', assignment_row.starts_at,
        'completes_at', assignment_row.completes_at,
        'reserved_until', assignment_row.reserved_until,
        'route_id', route.id,
        'route_code', route.route_code,
        'route_name', route.route_name,
        'vehicle_id', vehicle.id,
        'registration_number', vehicle.registration_number,
        'ownership_type', vehicle.ownership_type,
        'learner_capacity', vehicle.learner_capacity
    ) INTO NEW.context_snapshot
    FROM transport_routes route, transport_vehicles vehicle
    WHERE route.school_id = NEW.school_id AND route.id = schedule_row.route_id
      AND vehicle.school_id = NEW.school_id AND vehicle.id = assignment_row.vehicle_id;

    SELECT jsonb_build_object(
        'driver', (
            SELECT jsonb_build_object('id', id, 'first_name', first_name, 'last_name', last_name)
            FROM users WHERE school_id = NEW.school_id AND id = assignment_row.driver_id
        ),
        'attendant', (
            SELECT jsonb_build_object('id', id, 'first_name', first_name, 'last_name', last_name)
            FROM users WHERE school_id = NEW.school_id AND id = assignment_row.attendant_id
        )
    ) INTO NEW.staff_snapshot;

    SELECT count(*)::integer INTO NEW.roster_count
    FROM transport_learner_reservations
    WHERE school_id = NEW.school_id AND assignment_id = NEW.assignment_id AND active;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_trip_roster_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' OR pg_trigger_depth() < 2 THEN
        RAISE EXCEPTION 'Roster rows are created only by atomic trip capture'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_trip_capture_roster()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    inserted_count integer;
BEGIN
    INSERT INTO transport_trip_roster (
        id, school_id, trip_id, reservation_id, learner_id, stop_id,
        learner_snapshot, stop_snapshot, reservation_snapshot
    )
    SELECT
        gen_random_uuid(), NEW.school_id, NEW.id, reservation.id,
        reservation.learner_id, reservation.stop_id,
        jsonb_build_object(
            'id', learner.id,
            'admission_no', learner.admission_no,
            'first_name', learner.first_name,
            'last_name', learner.last_name
        ),
        jsonb_build_object(
            'id', stop.id, 'route_id', stop.route_id, 'direction', stop.direction,
            'stop_name', stop.stop_name, 'stop_order', stop.stop_order
        ),
        jsonb_build_object(
            'id', reservation.id,
            'service_date', reservation.service_date,
            'direction', reservation.direction,
            'starts_at', reservation.starts_at,
            'completes_at', reservation.completes_at,
            'lock_version', reservation.lock_version
        )
    FROM transport_learner_reservations reservation
    JOIN learners learner
      ON learner.school_id = reservation.school_id AND learner.id = reservation.learner_id
    JOIN transport_route_stops stop
      ON stop.school_id = reservation.school_id AND stop.id = reservation.stop_id
    WHERE reservation.school_id = NEW.school_id
      AND reservation.assignment_id = NEW.assignment_id AND reservation.active;

    GET DIAGNOSTICS inserted_count = ROW_COUNT;

    IF inserted_count <> NEW.roster_count THEN
        RAISE EXCEPTION 'Captured roster count mismatch'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_trip_reservation_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    assignment_uuid uuid;
    school_uuid uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        assignment_uuid := OLD.assignment_id;
        school_uuid := OLD.school_id;
    ELSE
        assignment_uuid := NEW.assignment_id;
        school_uuid := NEW.school_id;
    END IF;

    PERFORM 1 FROM transport_dated_assignments
    WHERE school_id = school_uuid AND id = assignment_uuid
    FOR UPDATE;

    IF EXISTS (
        SELECT 1 FROM transport_trips
        WHERE school_id = school_uuid AND assignment_id = assignment_uuid
    ) THEN
        RAISE EXCEPTION 'Captured roster requires an explicit operational correction'
            USING ERRCODE = '23P01';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_captured_assignment_guard()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.active AND NOT NEW.active AND EXISTS (
        SELECT 1 FROM transport_trips
        WHERE school_id = OLD.school_id AND assignment_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'Captured assignments require an operational lifecycle action'
            USING ERRCODE = '23P01';
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_trip_capture_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_trips
FOR EACH ROW EXECUTE FUNCTION public.transport_trip_capture_validate();

CREATE TRIGGER transport_trip_roster_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_trip_roster
FOR EACH ROW EXECUTE FUNCTION public.transport_trip_roster_validate();

CREATE TRIGGER transport_trip_capture_roster
AFTER INSERT ON transport_trips
FOR EACH ROW EXECUTE FUNCTION public.transport_trip_capture_roster();

CREATE TRIGGER transport_trip_reservation_guard
BEFORE INSERT OR UPDATE OR DELETE ON transport_learner_reservations
FOR EACH ROW EXECUTE FUNCTION public.transport_trip_reservation_guard();

CREATE TRIGGER transport_captured_assignment_guard
BEFORE UPDATE ON transport_dated_assignments
FOR EACH ROW EXECUTE FUNCTION public.transport_captured_assignment_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['transport_trip_roster', 'transport_trips'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain captured trip evidence; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER transport_captured_assignment_guard ON transport_dated_assignments;
DROP TRIGGER transport_trip_reservation_guard ON transport_learner_reservations;
DROP TABLE transport_trip_roster;
DROP TABLE transport_trips;
DROP FUNCTION public.transport_captured_assignment_guard();
DROP FUNCTION public.transport_trip_reservation_guard();
DROP FUNCTION public.transport_trip_capture_roster();
DROP FUNCTION public.transport_trip_roster_validate();
DROP FUNCTION public.transport_trip_capture_validate();
SQL);
    }
};
