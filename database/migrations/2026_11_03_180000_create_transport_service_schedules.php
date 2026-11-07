<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport schedules require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_service_schedules (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    route_id uuid NOT NULL,
    service_code varchar(40) NOT NULL CHECK (length(btrim(service_code)) > 0),
    direction varchar(20) NOT NULL
        CHECK (direction IN ('to_school', 'from_school')),
    timezone varchar(100) NOT NULL,
    weekday_mask integer NOT NULL CHECK (weekday_mask BETWEEN 1 AND 127),
    effective_from date NOT NULL CHECK (isfinite(effective_from)),
    effective_to date CHECK (isfinite(effective_to)),
    departure_time time(0) without time zone NOT NULL,
    completion_time time(0) without time zone NOT NULL,
    completion_day_offset integer NOT NULL DEFAULT 0
        CHECK (completion_day_offset IN (0, 1)),
    turnaround_minutes integer NOT NULL DEFAULT 0
        CHECK (turnaround_minutes BETWEEN 0 AND 1440),
    active boolean NOT NULL DEFAULT true,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT transport_schedule_owner_key UNIQUE (school_id, id),
    CONSTRAINT transport_schedule_context_key
        UNIQUE (school_id, route_id, direction, id),
    CONSTRAINT transport_schedule_route_fk
        FOREIGN KEY (school_id, route_id)
        REFERENCES transport_routes(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_schedule_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_schedule_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_schedule_dates_valid
        CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT transport_schedule_window_valid CHECK (
        departure_time < time '24:00'
        AND completion_time < time '24:00'
        AND (
            (completion_day_offset = 0 AND completion_time > departure_time)
            OR
            (completion_day_offset = 1 AND completion_time <= departure_time)
        )
    )
);

CREATE UNIQUE INDEX transport_schedule_code_key
ON transport_service_schedules (school_id, upper(btrim(service_code)));

CREATE OR REPLACE FUNCTION public.transport_schedule_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retain transport schedule history'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_timezone_names WHERE name = NEW.timezone
    ) THEN
        RAISE EXCEPTION 'Unknown transport schedule timezone'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.lock_version <> 1 OR NEW.created_by <> NEW.updated_by THEN
            RAISE EXCEPTION 'Invalid initial schedule version or actor'
                USING ERRCODE = '23514';
        END IF;

        NEW.created_at := clock_timestamp();
        NEW.updated_at := NEW.created_at;
    ELSE
        -- A changed timetable gets a new identity. Only retirement/reactivation
        -- is available here; future trip evidence must retain its timetable.
        IF (NEW.id, NEW.school_id, NEW.route_id, NEW.service_code,
            NEW.direction, NEW.timezone, NEW.weekday_mask,
            NEW.effective_from, NEW.effective_to,
            NEW.departure_time, NEW.completion_time,
            NEW.completion_day_offset, NEW.turnaround_minutes,
            NEW.created_by, NEW.created_at)
           IS DISTINCT FROM
           (OLD.id, OLD.school_id, OLD.route_id, OLD.service_code,
            OLD.direction, OLD.timezone, OLD.weekday_mask,
            OLD.effective_from, OLD.effective_to,
            OLD.departure_time, OLD.completion_time,
            OLD.completion_day_offset, OLD.turnaround_minutes,
            OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'Transport schedule definition is immutable'
                USING ERRCODE = '23514';
        END IF;

        NEW.lock_version := OLD.lock_version + 1;
        NEW.updated_at := clock_timestamp();
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_schedule_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_service_schedules
FOR EACH ROW EXECUTE FUNCTION public.transport_schedule_validate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('transport_service_schedules')->exists()) {
            throw new RuntimeException(
                'Retain transport schedule records; use a reviewed forward migration.'
            );
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_service_schedules;
DROP FUNCTION public.transport_schedule_validate();
SQL);
    }
};
