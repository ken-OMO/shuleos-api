<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Transport foundation requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
CREATE TABLE transport_vehicles (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    registration_number varchar(32) NOT NULL
        CHECK (length(btrim(registration_number)) > 0),
    ownership_type varchar(20) NOT NULL
        CHECK (ownership_type IN ('school_owned', 'contracted')),
    learner_capacity integer NOT NULL CHECK (learner_capacity > 0),
    active boolean NOT NULL DEFAULT true,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT transport_vehicle_owner_key UNIQUE (school_id, id),
    CONSTRAINT transport_vehicle_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_vehicle_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX transport_vehicle_registration_key
ON transport_vehicles (school_id, upper(btrim(registration_number)));

-- Upgrade the baseline route identity without replacing its incoming FKs.
-- Existing records need a separately reviewed attribution/backfill workflow.
LOCK TABLE transport_routes IN ACCESS EXCLUSIVE MODE;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM transport_routes) THEN
        RAISE EXCEPTION
            'Existing transport routes require a reviewed backfill before foundation upgrade'
            USING ERRCODE = '23514';
    END IF;
END $$;

ALTER TABLE transport_routes
    ADD COLUMN route_code varchar(40) NOT NULL
        CHECK (length(btrim(route_code)) > 0),
    ADD COLUMN lock_version bigint NOT NULL DEFAULT 1
        CHECK (lock_version > 0),
    ADD COLUMN created_by uuid NOT NULL,
    ADD COLUMN updated_by uuid NOT NULL,
    ADD COLUMN updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    ALTER COLUMN route_name TYPE varchar(150),
    ALTER COLUMN active SET NOT NULL,
    ALTER COLUMN created_at DROP DEFAULT,
    ALTER COLUMN created_at TYPE timestamptz
        USING created_at AT TIME ZONE 'UTC',
    ALTER COLUMN created_at SET DEFAULT clock_timestamp(),
    ALTER COLUMN created_at SET NOT NULL,
    ADD CONSTRAINT transport_route_name_nonblank
        CHECK (length(btrim(route_name)) > 0),
    ADD CONSTRAINT transport_route_owner_key UNIQUE (school_id, id),
    ADD CONSTRAINT transport_route_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT transport_route_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT;

CREATE UNIQUE INDEX transport_route_code_key
ON transport_routes (school_id, upper(btrim(route_code)));

CREATE TABLE transport_route_stops (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT,
    route_id uuid NOT NULL,
    direction varchar(20) NOT NULL
        CHECK (direction IN ('to_school', 'from_school')),
    stop_name varchar(150) NOT NULL CHECK (length(btrim(stop_name)) > 0),
    stop_order integer NOT NULL CHECK (stop_order > 0),
    active boolean NOT NULL DEFAULT true,
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    created_by uuid NOT NULL,
    updated_by uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT transport_stop_owner_key UNIQUE (school_id, id),
    CONSTRAINT transport_stop_context_key
        UNIQUE (school_id, route_id, direction, id),
    CONSTRAINT transport_stop_route_fk
        FOREIGN KEY (school_id, route_id)
        REFERENCES transport_routes(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_stop_creator_fk
        FOREIGN KEY (school_id, created_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT,
    CONSTRAINT transport_stop_updater_fk
        FOREIGN KEY (school_id, updated_by)
        REFERENCES users(school_id, id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX transport_stop_active_order_key
ON transport_route_stops (school_id, route_id, direction, stop_order)
WHERE active;

CREATE OR REPLACE FUNCTION public.transport_foundation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Retire transport resources; retain their identities'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.lock_version <> 1 OR NEW.updated_by <> NEW.created_by THEN
            RAISE EXCEPTION 'Invalid initial transport resource version or actor'
                USING ERRCODE = '23514';
        END IF;

        NEW.created_at := clock_timestamp();
        NEW.updated_at := NEW.created_at;
    ELSE
        IF (NEW.id, NEW.school_id, NEW.created_by, NEW.created_at)
           IS DISTINCT FROM
           (OLD.id, OLD.school_id, OLD.created_by, OLD.created_at) THEN
            RAISE EXCEPTION 'Transport resource ownership is immutable'
                USING ERRCODE = '23514';
        END IF;

        IF TG_TABLE_NAME = 'transport_route_stops' THEN
            IF (NEW.route_id, NEW.direction)
               IS DISTINCT FROM (OLD.route_id, OLD.direction) THEN
                RAISE EXCEPTION 'Retain stop route and direction identity'
                    USING ERRCODE = '23514';
            END IF;
        END IF;

        NEW.lock_version := OLD.lock_version + 1;
        NEW.updated_at := clock_timestamp();
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER transport_vehicle_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_vehicles
FOR EACH ROW EXECUTE FUNCTION public.transport_foundation_validate();

CREATE TRIGGER transport_route_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_routes
FOR EACH ROW EXECUTE FUNCTION public.transport_foundation_validate();

CREATE TRIGGER transport_stop_validate
BEFORE INSERT OR UPDATE OR DELETE ON transport_route_stops
FOR EACH ROW EXECUTE FUNCTION public.transport_foundation_validate();
SQL);
    }

    public function down(): void
    {
        foreach ([
            'transport_route_stops',
            'transport_routes',
            'transport_vehicles',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Retain transport foundation records; use a reviewed forward migration.'
                );
            }
        }

        DB::unprepared(<<<'SQL'
DROP TABLE transport_route_stops;

-- Retain the baseline route table and all its pre-existing relationships.
DROP TRIGGER transport_route_validate ON transport_routes;
DROP INDEX transport_route_code_key;

ALTER TABLE transport_routes
    DROP CONSTRAINT transport_route_creator_fk,
    DROP CONSTRAINT transport_route_updater_fk,
    DROP CONSTRAINT transport_route_owner_key,
    DROP CONSTRAINT transport_route_name_nonblank,
    DROP COLUMN route_code,
    DROP COLUMN lock_version,
    DROP COLUMN created_by,
    DROP COLUMN updated_by,
    DROP COLUMN updated_at,
    ALTER COLUMN route_name TYPE varchar(255),
    ALTER COLUMN active DROP NOT NULL,
    ALTER COLUMN created_at DROP DEFAULT,
    ALTER COLUMN created_at TYPE timestamp without time zone
        USING created_at AT TIME ZONE 'UTC',
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN created_at DROP NOT NULL;

DROP TABLE transport_vehicles;
DROP FUNCTION public.transport_foundation_validate();
SQL);
    }
};
