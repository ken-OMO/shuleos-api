<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE student_election_commercial_openings ALTER COLUMN period_id DROP NOT NULL;
CREATE OR REPLACE FUNCTION election_commercial_opening_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Commercial opening evidence is immutable'; END IF;
    PERFORM 1 FROM student_elections WHERE id = NEW.election_id AND school_id = NEW.school_id FOR UPDATE;
    IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM users WHERE id = NEW.created_by AND school_id = NEW.school_id)
        OR NOT EXISTS(SELECT 1 FROM school_subscriptions WHERE id = NEW.main_subscription_id AND school_id = NEW.school_id)
        OR (NEW.period_id IS NOT NULL AND NOT EXISTS(
            SELECT 1 FROM student_election_addon_periods WHERE id = NEW.period_id AND school_id = NEW.school_id
                AND NEW.checked_at >= starts_at AND NEW.checked_at < ends_at))
        OR (NEW.period_id IS NULL AND NOT EXISTS(
            SELECT 1 FROM school_subscriptions sub
                JOIN subscription_packages p ON p.id = sub.package_id
                JOIN schools s ON s.id = sub.school_id
            WHERE sub.id = NEW.main_subscription_id AND sub.school_id = NEW.school_id
                AND LOWER(TRIM(p.package_name)) = 'enterprise'
                AND s.active AND NOT s.is_deleted AND s.lifecycle_state = 'active'
                AND sub.expiry_date IS NOT NULL
                AND sub.status IN ('ACTIVE', 'GRACE_PERIOD')
                AND (NEW.checked_at AT TIME ZONE COALESCE(NULLIF(s.timezone, ''), 'UTC'))::date
                    BETWEEN sub.start_date AND CASE WHEN sub.status = 'GRACE_PERIOD' THEN sub.grace_end_date ELSE sub.expiry_date END))
        OR (NEW.round_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM student_election_rounds WHERE id = NEW.round_id AND election_id = NEW.election_id AND school_id = NEW.school_id)) THEN
        RAISE EXCEPTION 'Commercial opening scope or period mismatch';
    END IF;
    RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain opening evidence; use a reviewed forward migration.');
    }
};
