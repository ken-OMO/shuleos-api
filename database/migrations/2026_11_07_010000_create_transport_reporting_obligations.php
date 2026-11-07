<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport reporting requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_reporting_policies (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    schedule_id uuid NOT NULL,
    effective_from date NOT NULL CHECK (isfinite(effective_from)),
    effective_to date NOT NULL CHECK (isfinite(effective_to)),
    weekday_mask integer NOT NULL CHECK (weekday_mask BETWEEN 1 AND 127),
    deadline_time time(0) NOT NULL CHECK (deadline_time < time '24:00'),
    deadline_day_offset integer NOT NULL CHECK (deadline_day_offset BETWEEN 0 AND 2),
    grace_minutes integer NOT NULL CHECK (grace_minutes BETWEEN 0 AND 1440),
    timezone varchar(100) NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    CHECK (effective_to >= effective_from AND effective_to - effective_from <= 365),
    FOREIGN KEY (school_id, schedule_id)
        REFERENCES transport_service_schedules(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    EXCLUDE USING gist (
        school_id WITH =,
        schedule_id WITH =,
        daterange(effective_from, effective_to, '[]') WITH &&
    )
);

CREATE TABLE transport_reporting_obligations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    policy_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    service_date date NOT NULL CHECK (isfinite(service_date)),
    timezone varchar(100) NOT NULL,
    deadline_at timestamptz NOT NULL,
    grace_until timestamptz NOT NULL,
    policy_snapshot jsonb NOT NULL,
    schedule_snapshot jsonb NOT NULL,
    materialized_by uuid NOT NULL,
    materialized_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    UNIQUE (school_id, schedule_id, service_date),
    CHECK (grace_until >= deadline_at),
    FOREIGN KEY (school_id, policy_id)
        REFERENCES transport_reporting_policies(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, schedule_id)
        REFERENCES transport_service_schedules(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, materialized_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE OR REPLACE FUNCTION public.transport_reporting_policy_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    schedule_row transport_service_schedules%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Reporting policy episodes are immutable'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = NEW.schedule_id
    FOR SHARE;

    IF NOT FOUND OR schedule_row.active IS NOT TRUE THEN
        RAISE EXCEPTION 'Active same-school schedule required'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.effective_from < schedule_row.effective_from
        OR NEW.effective_to > schedule_row.effective_to
        OR (NEW.weekday_mask & schedule_row.weekday_mask) <> NEW.weekday_mask
        OR NEW.deadline_day_offset < schedule_row.completion_day_offset
        OR (NEW.deadline_day_offset = schedule_row.completion_day_offset
            AND NEW.deadline_time < schedule_row.completion_time) THEN
        RAISE EXCEPTION 'Reporting policy must fit schedule dates, weekdays and completion'
            USING ERRCODE = '23514';
    END IF;

    NEW.timezone := schedule_row.timezone;
    NEW.created_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_reporting_obligation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    policy_row transport_reporting_policies%ROWTYPE;
    schedule_row transport_service_schedules%ROWTYPE;
    scheduled_end timestamptz;
    assigned_end timestamptz;
    local_deadline timestamp;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Reporting obligation and deadline evidence are immutable'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO policy_row FROM transport_reporting_policies
    WHERE school_id = NEW.school_id AND id = NEW.policy_id
    FOR SHARE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school reporting policy required'
            USING ERRCODE = '23503';
    END IF;

    SELECT * INTO schedule_row FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = policy_row.schedule_id
    FOR SHARE;

    IF NOT FOUND OR schedule_row.active IS NOT TRUE
        OR NEW.service_date NOT BETWEEN policy_row.effective_from AND policy_row.effective_to
        OR (policy_row.weekday_mask
            & (1 << (extract(isodow FROM NEW.service_date)::integer - 1))) = 0 THEN
        RAISE EXCEPTION 'Applicable active reporting service day required'
            USING ERRCODE = '23514';
    END IF;

    NEW.schedule_id := policy_row.schedule_id;
    NEW.timezone := policy_row.timezone;
    local_deadline := (NEW.service_date + policy_row.deadline_day_offset)
        + policy_row.deadline_time;
    NEW.deadline_at := local_deadline AT TIME ZONE policy_row.timezone;

    IF (NEW.deadline_at AT TIME ZONE policy_row.timezone) <> local_deadline THEN
        RAISE EXCEPTION 'Reporting deadline falls in an invalid local-time gap'
            USING ERRCODE = '23514';
    END IF;

    scheduled_end := (
        (NEW.service_date + schedule_row.completion_day_offset)
        + schedule_row.completion_time
    ) AT TIME ZONE schedule_row.timezone;

    SELECT MAX(completes_at) INTO assigned_end FROM transport_dated_assignments
    WHERE school_id = NEW.school_id AND schedule_id = NEW.schedule_id
        AND service_date = NEW.service_date;

    IF NEW.deadline_at < scheduled_end
        OR (assigned_end IS NOT NULL AND NEW.deadline_at < assigned_end) THEN
        RAISE EXCEPTION 'Reporting deadline must account for final service completion'
            USING ERRCODE = '23514';
    END IF;

    NEW.grace_until := NEW.deadline_at + make_interval(mins => policy_row.grace_minutes);
    NEW.policy_snapshot := jsonb_build_object(
        'policy_id', policy_row.id,
        'effective_from', policy_row.effective_from,
        'effective_to', policy_row.effective_to,
        'weekday_mask', policy_row.weekday_mask,
        'deadline_time', policy_row.deadline_time,
        'deadline_day_offset', policy_row.deadline_day_offset,
        'grace_minutes', policy_row.grace_minutes,
        'timezone', policy_row.timezone
    );
    NEW.schedule_snapshot := jsonb_build_object(
        'schedule_id', schedule_row.id,
        'service_code', schedule_row.service_code,
        'route_id', schedule_row.route_id,
        'direction', schedule_row.direction,
        'departure_time', schedule_row.departure_time,
        'completion_time', schedule_row.completion_time,
        'completion_day_offset', schedule_row.completion_day_offset,
        'timezone', schedule_row.timezone
    );
    NEW.materialized_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_reporting_policy_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_reporting_policies
FOR EACH ROW EXECUTE FUNCTION public.transport_reporting_policy_validate();

CREATE TRIGGER transport_reporting_obligation_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_reporting_obligations
FOR EACH ROW EXECUTE FUNCTION public.transport_reporting_obligation_validate();
SQL);
    }

    public function down(): void
    {
        foreach (['transport_reporting_obligations', 'transport_reporting_policies'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain reporting obligation history; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_reporting_obligations;
DROP TABLE transport_reporting_policies;
DROP FUNCTION public.transport_reporting_obligation_validate();
DROP FUNCTION public.transport_reporting_policy_validate();
SQL);
    }
};
