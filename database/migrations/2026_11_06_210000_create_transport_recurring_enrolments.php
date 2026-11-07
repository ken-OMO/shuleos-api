<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport enrolments require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_enrolment_episodes (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    learner_id uuid NOT NULL,
    schedule_id uuid NOT NULL,
    stop_id uuid NOT NULL,
    direction varchar(20) NOT NULL
        CHECK (direction IN ('to_school', 'from_school')),
    run_number integer NOT NULL DEFAULT 1 CHECK (run_number > 0),
    effective_from date NOT NULL CHECK (isfinite(effective_from)),
    effective_to date NOT NULL CHECK (isfinite(effective_to)),
    weekday_mask integer NOT NULL CHECK (weekday_mask BETWEEN 1 AND 127),
    ended_before date,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    FOREIGN KEY (school_id, learner_id)
        REFERENCES learners(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, schedule_id)
        REFERENCES transport_service_schedules(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, stop_id)
        REFERENCES transport_route_stops(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CHECK (effective_to >= effective_from AND effective_to - effective_from <= 365),
    CHECK (
        ended_before IS NULL OR (
            isfinite(ended_before)
            AND ended_before BETWEEN effective_from AND effective_to
        )
    )
);

CREATE TABLE transport_enrolment_reservations (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    episode_id uuid NOT NULL,
    reservation_id uuid NOT NULL UNIQUE,
    service_date date NOT NULL,
    UNIQUE (episode_id, service_date),
    FOREIGN KEY (school_id, episode_id)
        REFERENCES transport_enrolment_episodes(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, reservation_id)
        REFERENCES transport_learner_reservations(school_id, id) ON DELETE RESTRICT
);

CREATE TABLE transport_enrolment_transfers (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    source_episode_id uuid NOT NULL UNIQUE,
    target_episode_id uuid NOT NULL UNIQUE,
    effective_from date NOT NULL,
    reason varchar(1000) NOT NULL CHECK (length(btrim(reason)) > 0),
    created_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK (source_episode_id <> target_episode_id),
    FOREIGN KEY (school_id, source_episode_id)
        REFERENCES transport_enrolment_episodes(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, target_episode_id)
        REFERENCES transport_enrolment_episodes(school_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE OR REPLACE FUNCTION public.transport_enrolment_episode_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    schedule_row transport_service_schedules%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retain recurring enrolment history'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF (to_jsonb(NEW) - ARRAY['ended_before','lock_version','updated_by','updated_at'])
           IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['ended_before','lock_version','updated_by','updated_at'])
           OR OLD.ended_before IS NOT NULL
           OR NEW.ended_before IS NULL THEN
            RAISE EXCEPTION 'Enrolment definition is immutable; retain its episode'
                USING ERRCODE = '23514';
        END IF;

        NEW.lock_version := OLD.lock_version + 1;
        NEW.updated_at := clock_timestamp();
        RETURN NEW;
    END IF;

    IF NEW.lock_version <> 1 OR NEW.created_by <> NEW.updated_by
       OR NEW.ended_before IS NOT NULL THEN
        RAISE EXCEPTION 'Invalid initial enrolment episode'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO schedule_row
    FROM transport_service_schedules
    WHERE school_id = NEW.school_id AND id = NEW.schedule_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school schedule required'
            USING ERRCODE = '23503';
    END IF;

    IF NOT schedule_row.active
       OR NEW.direction <> schedule_row.direction
       OR NEW.effective_from < schedule_row.effective_from
       OR (schedule_row.effective_to IS NOT NULL
           AND NEW.effective_to > schedule_row.effective_to)
       OR (NEW.weekday_mask & schedule_row.weekday_mask) <> NEW.weekday_mask
       OR NOT EXISTS (
           SELECT 1 FROM transport_route_stops
           WHERE school_id = NEW.school_id AND id = NEW.stop_id
             AND route_id = schedule_row.route_id
             AND direction = NEW.direction AND active
       ) THEN
        RAISE EXCEPTION 'Enrolment must match the active schedule and stop'
            USING ERRCODE = '23514';
    END IF;

    NEW.created_at := clock_timestamp();
    NEW.updated_at := NEW.created_at;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_enrolment_link_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    episode_row transport_enrolment_episodes%ROWTYPE;
    reservation_row transport_learner_reservations%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Retain enrolment reservation links'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO episode_row FROM transport_enrolment_episodes
    WHERE school_id = NEW.school_id AND id = NEW.episode_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school episode required'
            USING ERRCODE = '23503';
    END IF;

    SELECT * INTO reservation_row FROM transport_learner_reservations
    WHERE school_id = NEW.school_id AND id = NEW.reservation_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school reservation required'
            USING ERRCODE = '23503';
    END IF;

    IF reservation_row.learner_id <> episode_row.learner_id
       OR reservation_row.stop_id <> episode_row.stop_id
       OR reservation_row.direction <> episode_row.direction
       OR reservation_row.service_date <> NEW.service_date
       OR NEW.service_date NOT BETWEEN episode_row.effective_from AND episode_row.effective_to
       OR (episode_row.weekday_mask
           & (1 << (extract(isodow FROM NEW.service_date)::integer - 1))) = 0
       OR NOT EXISTS (
           SELECT 1 FROM transport_dated_assignments
           WHERE school_id = NEW.school_id AND id = reservation_row.assignment_id
             AND schedule_id = episode_row.schedule_id
             AND run_number = episode_row.run_number
       ) THEN
        RAISE EXCEPTION 'Reservation does not belong to this enrolment episode'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.transport_enrolment_transfer_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    source_row transport_enrolment_episodes%ROWTYPE;
    target_row transport_enrolment_episodes%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Retain enrolment transfer history'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO source_row FROM transport_enrolment_episodes
    WHERE school_id = NEW.school_id AND id = NEW.source_episode_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school source episode required'
            USING ERRCODE = '23503';
    END IF;

    SELECT * INTO target_row FROM transport_enrolment_episodes
    WHERE school_id = NEW.school_id AND id = NEW.target_episode_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Same-school target episode required'
            USING ERRCODE = '23503';
    END IF;

    IF source_row.learner_id <> target_row.learner_id
       OR source_row.direction <> target_row.direction
       OR source_row.ended_before IS DISTINCT FROM NEW.effective_from
       OR target_row.effective_from <> NEW.effective_from
       OR target_row.effective_to <> source_row.effective_to
       OR target_row.weekday_mask <> source_row.weekday_mask THEN
        RAISE EXCEPTION 'Transfer must preserve learner, direction and remaining service dates'
            USING ERRCODE = '23514';
    END IF;

    NEW.created_at := clock_timestamp();
    RETURN NEW;
END $$;

CREATE TRIGGER transport_enrolment_episode_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_enrolment_episodes
FOR EACH ROW EXECUTE FUNCTION public.transport_enrolment_episode_validate();

CREATE TRIGGER transport_enrolment_link_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_enrolment_reservations
FOR EACH ROW EXECUTE FUNCTION public.transport_enrolment_link_validate();

CREATE TRIGGER transport_enrolment_transfer_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_enrolment_transfers
FOR EACH ROW EXECUTE FUNCTION public.transport_enrolment_transfer_validate();
SQL);
    }

    public function down(): void
    {
        foreach ([
            'transport_enrolment_transfers',
            'transport_enrolment_reservations',
            'transport_enrolment_episodes',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain recurring enrolment evidence; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_enrolment_transfers;
DROP TABLE transport_enrolment_reservations;
DROP TABLE transport_enrolment_episodes;
DROP FUNCTION public.transport_enrolment_transfer_validate();
DROP FUNCTION public.transport_enrolment_link_validate();
DROP FUNCTION public.transport_enrolment_episode_validate();
SQL);
    }
};
