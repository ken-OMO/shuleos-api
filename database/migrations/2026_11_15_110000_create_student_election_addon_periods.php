<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE TABLE student_election_addon_periods (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id),
    request_id uuid NOT NULL UNIQUE,
    previous_id uuid,
    starts_at timestamptz NOT NULL,
    ends_at timestamptz NOT NULL,
    commercial_timezone text NOT NULL,
    evidence_source text NOT NULL CHECK (evidence_source = 'platform_manual_attestation'),
    evidence_reference varchar(100) NOT NULL,
    reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 10 AND 1000),
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    activated_by uuid NOT NULL REFERENCES users(id),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (school_id, id),
    UNIQUE (school_id, evidence_reference),
    FOREIGN KEY (school_id, previous_id) REFERENCES student_election_addon_periods(school_id, id),
    CHECK (ends_at > starts_at),
    CHECK (isfinite(starts_at) AND isfinite(ends_at)),
    CHECK (evidence_reference = upper(btrim(evidence_reference)) AND length(evidence_reference) > 0),
    EXCLUDE USING gist (school_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
);
CREATE INDEX student_election_addon_period_lookup ON student_election_addon_periods(school_id, ends_at DESC);

CREATE OR REPLACE FUNCTION public.student_election_addon_period_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    previous_row student_election_addon_periods%ROWTYPE;
    actor_row users%ROWTYPE;
    latest_id uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Elections entitlement periods are immutable' USING ERRCODE = '23514';
    END IF;
    -- Same first lock as Elections mutations and report snapshots.
    PERFORM 1 FROM schools WHERE id=NEW.school_id FOR UPDATE;
    SELECT * INTO actor_row FROM users WHERE id=NEW.activated_by FOR SHARE;
    IF NOT FOUND OR actor_row.school_id IS NOT NULL OR actor_row.active IS NOT TRUE
        OR actor_row.is_deleted IS NOT FALSE OR actor_row.suspended_at IS NOT NULL
        OR actor_row.account_locked_until > clock_timestamp() THEN
        RAISE EXCEPTION 'Eligible platform identity required' USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=NEW.commercial_timezone) THEN
        RAISE EXCEPTION 'Explicit valid commercial timezone required' USING ERRCODE = '23514';
    END IF;
    IF NEW.ends_at IS DISTINCT FROM (((NEW.starts_at AT TIME ZONE NEW.commercial_timezone) + interval '12 months') AT TIME ZONE NEW.commercial_timezone) THEN
        RAISE EXCEPTION 'Exactly twelve calendar months required' USING ERRCODE = '23514';
    END IF;
    SELECT id INTO latest_id FROM student_election_addon_periods
      WHERE school_id=NEW.school_id ORDER BY ends_at DESC, id DESC LIMIT 1;
    IF NEW.previous_id IS DISTINCT FROM latest_id THEN
        RAISE EXCEPTION 'Refresh entitlement history before renewal' USING ERRCODE = '23P01';
    END IF;
    IF NEW.previous_id IS NOT NULL THEN
        SELECT * INTO previous_row FROM student_election_addon_periods
          WHERE school_id=NEW.school_id AND id=NEW.previous_id;
        IF NOT FOUND OR NEW.starts_at < previous_row.ends_at THEN
            RAISE EXCEPTION 'Renewal cannot overlap earlier entitlement' USING ERRCODE = '23P01';
        END IF;
    END IF;
    NEW.recorded_at := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER student_election_addon_period_validate
BEFORE INSERT OR UPDATE OR DELETE ON student_election_addon_periods
FOR EACH ROW EXECUTE FUNCTION public.student_election_addon_period_validate();
SQL);
        foreach ([
            'manage_student_election_subscriptions' => 'Record verified Elections add-on activation and renewal',
            'view_student_election_subscriptions' => 'View Elections subscriptions in platform scope',
            'view_own_student_election_subscription' => 'View the current school Elections subscription',
        ] as $name => $description) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'permission_name' => $name,
                'module_name' => 'student_election_subscription', 'description' => $description,
                'created_at' => now(),
            ]);
            if (! DB::table('permissions')->where('permission_name', $name)
                ->where('module_name', 'student_election_subscription')->exists()) {
                throw new RuntimeException('Conflicting Elections subscription permission.');
            }
        }
        // No role grants, school activation, price changes or payment collection.
    }

    public function down(): void
    {
        if (DB::table('student_election_addon_periods')->exists()) {
            throw new RuntimeException('Retain entitlement evidence; use a reviewed forward migration.');
        }
        DB::unprepared('DROP TABLE student_election_addon_periods; DROP FUNCTION public.student_election_addon_period_validate();');
        // Retain permission identities and btree_gist, which other modules share.
    }
};
