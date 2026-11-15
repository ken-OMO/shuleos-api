<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No subscriptions, dates, lifecycle states, permissions or grants are backfilled.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION shuleos_lock_main_subscription_school()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    old_school uuid;
    new_school uuid;
BEGIN
    IF TG_OP <> 'INSERT' THEN old_school := OLD.school_id; END IF;
    IF TG_OP <> 'DELETE' THEN new_school := NEW.school_id; END IF;
    PERFORM id FROM schools
      WHERE id = old_school OR id = new_school
      ORDER BY id FOR UPDATE;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS school_subscription_business_lock ON school_subscriptions;
CREATE TRIGGER school_subscription_business_lock
BEFORE INSERT OR UPDATE OR DELETE ON school_subscriptions
FOR EACH ROW EXECUTE FUNCTION shuleos_lock_main_subscription_school();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS school_subscription_business_lock ON school_subscriptions');
        DB::unprepared('DROP FUNCTION IF EXISTS shuleos_lock_main_subscription_school()');
    }
};
