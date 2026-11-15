<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE student_election_commercial_openings (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL REFERENCES schools(id),
    election_id uuid NOT NULL REFERENCES student_elections(id),
    scope_id uuid NOT NULL UNIQUE,
    round_id uuid REFERENCES student_election_rounds(id),
    period_id uuid NOT NULL,
    main_subscription_id uuid NOT NULL REFERENCES school_subscriptions(id),
    checked_at timestamptz NOT NULL,
    opens_at timestamptz NOT NULL,
    closes_at timestamptz NOT NULL CHECK(closes_at > opens_at),
    created_by uuid NOT NULL REFERENCES users(id),
    FOREIGN KEY(school_id, period_id) REFERENCES student_election_addon_periods(school_id, id),
    CHECK(scope_id = COALESCE(round_id, election_id))
);
CREATE OR REPLACE FUNCTION election_commercial_opening_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Commercial opening evidence is immutable'; END IF;
    PERFORM 1 FROM student_elections WHERE id = NEW.election_id AND school_id = NEW.school_id FOR UPDATE;
    IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM users WHERE id = NEW.created_by AND school_id = NEW.school_id)
        OR NOT EXISTS(SELECT 1 FROM school_subscriptions WHERE id = NEW.main_subscription_id AND school_id = NEW.school_id)
        OR NOT EXISTS(SELECT 1 FROM student_election_addon_periods WHERE id = NEW.period_id AND school_id = NEW.school_id AND NEW.checked_at >= starts_at AND NEW.checked_at < ends_at)
        OR (NEW.round_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM student_election_rounds WHERE id = NEW.round_id AND election_id = NEW.election_id AND school_id = NEW.school_id)) THEN
        RAISE EXCEPTION 'Commercial opening scope or period mismatch';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER election_commercial_opening BEFORE INSERT OR UPDATE OR DELETE ON student_election_commercial_openings FOR EACH ROW EXECUTE FUNCTION election_commercial_opening_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain opening evidence; use a reviewed forward migration.');
    }
};
