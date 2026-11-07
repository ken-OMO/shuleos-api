<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport assignments require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE transport_dated_assignments (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    schedule_id uuid NOT NULL,
    service_date date NOT NULL CHECK (isfinite(service_date)),
    run_number integer NOT NULL DEFAULT 1 CHECK (run_number > 0),
    vehicle_id uuid NOT NULL,
    driver_id uuid NOT NULL,
    attendant_id uuid,
    starts_at timestamptz NOT NULL,
    completes_at timestamptz NOT NULL,
    reserved_until timestamptz NOT NULL,
    active boolean NOT NULL DEFAULT true,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT transport_assignment_owner_key UNIQUE (school_id, id),
    CONSTRAINT transport_assignment_schedule_fk
        FOREIGN KEY (school_id, schedule_id)
        REFERENCES transport_service_schedules(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_assignment_vehicle_fk
        FOREIGN KEY (school_id, vehicle_id)
        REFERENCES transport_vehicles(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_assignment_driver_fk
        FOREIGN KEY (school_id, driver_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_assignment_attendant_fk
        FOREIGN KEY (school_id, attendant_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_assignment_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_assignment_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CHECK (attendant_id IS NULL OR attendant_id <> driver_id),
    CHECK (completes_at > starts_at AND reserved_until >= completes_at),
    CONSTRAINT transport_assignment_vehicle_overlap
        EXCLUDE USING gist (
            school_id WITH =,
            vehicle_id WITH =,
            tstzrange(starts_at, reserved_until, '[)') WITH &&
        ) WHERE (active)
);

CREATE UNIQUE INDEX transport_assignment_active_run_key
ON transport_dated_assignments (school_id, schedule_id, service_date, run_number)
WHERE active;

CREATE TABLE transport_staff_reservations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    assignment_id uuid NOT NULL,
    user_id uuid NOT NULL,
    responsibility varchar(20) NOT NULL
        CHECK (responsibility IN ('driver', 'attendant')),
    starts_at timestamptz NOT NULL,
    reserved_until timestamptz NOT NULL,
    active boolean NOT NULL,
    CONSTRAINT transport_staff_assignment_fk
        FOREIGN KEY (school_id, assignment_id)
        REFERENCES transport_dated_assignments(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_staff_user_fk
        FOREIGN KEY (school_id, user_id)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    UNIQUE (assignment_id, responsibility),
    CHECK (reserved_until > starts_at),
    CONSTRAINT transport_staff_overlap
        EXCLUDE USING gist (
            school_id WITH =,
            user_id WITH =,
            tstzrange(starts_at, reserved_until, '[)') WITH &&
        ) WHERE (active)
);

CREATE OR REPLACE FUNCTION public.transport_dated_assignment_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    schedule_row transport_service_schedules%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retain dated transport assignment history'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF (NEW.id, NEW.school_id, NEW.schedule_id, NEW.service_date,
            NEW.run_number, NEW.vehicle_id, NEW.driver_id, NEW.attendant_id,
            NEW.starts_at, NEW.completes_at, NEW.reserved_until,
            NEW.created_by, NEW.created_at)
           IS DISTINCT FROM
           (OLD.id, OLD.school_id, OLD.schedule_id, OLD.service_date,
            OLD.run_number, OLD.vehicle_id, OLD.driver_id, OLD.attendant_id,
            OLD.starts_at, OLD.completes_at, OLD.reserved_until,
            OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'Dated transport assignment identity is immutable'
                USING ERRCODE = '23514';
        END IF;

        IF NOT OLD.active AND NEW.active THEN
            RAISE EXCEPTION 'Ended assignments cannot be reactivated'
                USING ERRCODE = '23514';
        END IF;

        NEW.lock_version := OLD.lock_version + 1;
        NEW.updated_at := clock_timestamp();
        RETURN NEW;
    END IF;

    IF NEW.lock_version <> 1 OR NEW.created_by <> NEW.updated_by
       OR NOT NEW.active THEN
        RAISE EXCEPTION 'Invalid initial transport assignment'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row
    FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = NEW.schedule_id
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school schedule required'
            USING ERRCODE = '23503';
    END IF;

    IF NOT schedule_row.active
       OR NEW.service_date < schedule_row.effective_from
       OR (schedule_row.effective_to IS NOT NULL
           AND NEW.service_date > schedule_row.effective_to)
       OR (schedule_row.weekday_mask
           & (1 << (extract(isodow FROM NEW.service_date)::integer - 1))) = 0 THEN
        RAISE EXCEPTION 'Schedule does not operate on the selected date'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM transport_routes
        WHERE school_id = NEW.school_id AND id = schedule_row.route_id AND active
    ) OR NOT EXISTS (
        SELECT 1 FROM transport_vehicles
        WHERE school_id = NEW.school_id AND id = NEW.vehicle_id AND active
    ) THEN
        RAISE EXCEPTION 'Active same-school route and vehicle required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM users
        WHERE school_id = NEW.school_id AND id = NEW.driver_id
          AND active AND NOT is_deleted AND suspended_at IS NULL
          AND (account_locked_until IS NULL OR account_locked_until <= clock_timestamp())
    ) OR (NEW.attendant_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM users
        WHERE school_id = NEW.school_id AND id = NEW.attendant_id
          AND active AND NOT is_deleted AND suspended_at IS NULL
          AND (account_locked_until IS NULL OR account_locked_until <= clock_timestamp())
    )) THEN
        RAISE EXCEPTION 'Eligible same-school transport staff required'
            USING ERRCODE = '23514';
    END IF;

    NEW.starts_at :=
        (NEW.service_date + schedule_row.departure_time)
        AT TIME ZONE schedule_row.timezone;
    NEW.completes_at :=
        ((NEW.service_date + schedule_row.completion_day_offset)
         + schedule_row.completion_time)
        AT TIME ZONE schedule_row.timezone;
    NEW.reserved_until :=
        NEW.completes_at
        + make_interval(mins => schedule_row.turnaround_minutes);

    -- Reject wall-clock gaps rather than silently shifting a service time.
    IF NEW.starts_at AT TIME ZONE schedule_row.timezone
           <> NEW.service_date + schedule_row.departure_time
       OR NEW.completes_at AT TIME ZONE schedule_row.timezone
           <> (NEW.service_date + schedule_row.completion_day_offset)
              + schedule_row.completion_time THEN
        RAISE EXCEPTION 'Service time falls in a timezone clock gap'
            USING ERRCODE = '23514';
    END IF;

    NEW.created_at := clock_timestamp();
    NEW.updated_at := NEW.created_at;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_staff_reservation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    assignment_row transport_dated_assignments%ROWTYPE;
    expected_user uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retain transport staff reservation history'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO assignment_row
    FROM transport_dated_assignments
    WHERE school_id = NEW.school_id AND id = NEW.assignment_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school dated assignment required'
            USING ERRCODE = '23503';
    END IF;

    expected_user := CASE NEW.responsibility
        WHEN 'driver' THEN assignment_row.driver_id
        WHEN 'attendant' THEN assignment_row.attendant_id
        ELSE NULL END;

    IF expected_user IS NULL OR NEW.user_id <> expected_user
       OR NEW.starts_at <> assignment_row.starts_at
       OR NEW.reserved_until <> assignment_row.reserved_until
       OR NEW.active <> assignment_row.active THEN
        RAISE EXCEPTION 'Staff reservation must match its dated assignment'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE'
       AND (NEW.id, NEW.school_id, NEW.assignment_id, NEW.user_id,
            NEW.responsibility, NEW.starts_at, NEW.reserved_until)
           IS DISTINCT FROM
           (OLD.id, OLD.school_id, OLD.assignment_id, OLD.user_id,
            OLD.responsibility, OLD.starts_at, OLD.reserved_until) THEN
        RAISE EXCEPTION 'Staff reservation identity is immutable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_dated_assignment_reserve_staff()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO transport_staff_reservations (
            id, school_id, assignment_id, user_id, responsibility,
            starts_at, reserved_until, active
        ) VALUES (
            gen_random_uuid(), NEW.school_id, NEW.id, NEW.driver_id, 'driver',
            NEW.starts_at, NEW.reserved_until, NEW.active
        );

        IF NEW.attendant_id IS NOT NULL THEN
            INSERT INTO transport_staff_reservations (
                id, school_id, assignment_id, user_id, responsibility,
                starts_at, reserved_until, active
            ) VALUES (
                gen_random_uuid(), NEW.school_id, NEW.id, NEW.attendant_id, 'attendant',
                NEW.starts_at, NEW.reserved_until, NEW.active
            );
        END IF;
    ELSE
        UPDATE transport_staff_reservations
        SET active = NEW.active
        WHERE school_id = NEW.school_id AND assignment_id = NEW.id;
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_dated_assignment_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_dated_assignments
FOR EACH ROW EXECUTE FUNCTION public.transport_dated_assignment_validate();

CREATE TRIGGER transport_staff_reservation_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_staff_reservations
FOR EACH ROW EXECUTE FUNCTION public.transport_staff_reservation_validate();

CREATE TRIGGER transport_dated_assignment_reserve_staff
AFTER INSERT OR UPDATE ON transport_dated_assignments
FOR EACH ROW EXECUTE FUNCTION public.transport_dated_assignment_reserve_staff();
SQL);
    }

    public function down(): void
    {
        foreach (['transport_staff_reservations', 'transport_dated_assignments'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain dated transport assignments; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_staff_reservations;
DROP TABLE transport_dated_assignments;
DROP FUNCTION public.transport_dated_assignment_reserve_staff();
DROP FUNCTION public.transport_staff_reservation_validate();
DROP FUNCTION public.transport_dated_assignment_validate();
SQL);
        // btree_gist may be shared by other modules; retain the extension.
    }
};
