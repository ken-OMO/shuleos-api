<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Boarding reporting requires PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        Schema::create('boarding_reporting_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->integer('revision');
            $table->boolean('enabled');
            $table->date('effective_from');
            $table->string('timezone', 64);
            $table->string('deadline_time', 5);
            $table->smallInteger('deadline_day_offset');
            $table->integer('grace_minutes');
            // Bits 0 through 6 represent Monday through Sunday.
            $table->smallInteger('weekday_mask');
            $table->uuid('created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['school_id', 'id'], 'boarding_policy_owner_key');
            $table->unique(['school_id', 'revision'], 'boarding_policy_revision_key');
            $table->unique(['school_id', 'effective_from'], 'boarding_policy_effective_key');
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->foreign(['school_id', 'created_by'], 'boarding_policy_actor_fk')
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE boarding_reporting_policies
ADD CONSTRAINT boarding_policy_values_check CHECK (
    revision > 0
    AND length(btrim(timezone)) > 0
    AND deadline_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'
    AND deadline_day_offset IN (0, 1)
    AND grace_minutes BETWEEN 0 AND 1440
    AND weekday_mask BETWEEN 1 AND 127
)
SQL);

        Schema::create('boarding_daily_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('hostel_id');
            $table->uuid('policy_id');
            $table->date('reporting_date');
            $table->string('timezone', 64);
            $table->timestampTz('due_at');
            $table->uuid('created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['school_id', 'id'], 'boarding_obligation_owner_key');
            $table->unique(
                ['school_id', 'hostel_id', 'reporting_date'],
                'boarding_obligation_scope_key'
            );
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->foreign(['school_id', 'hostel_id'], 'boarding_obligation_hostel_fk')
                ->references(['school_id', 'id'])->on('hostels')->restrictOnDelete();
            $table->foreign(['school_id', 'policy_id'], 'boarding_obligation_policy_fk')
                ->references(['school_id', 'id'])->on('boarding_reporting_policies')
                ->restrictOnDelete();
            $table->foreign(['school_id', 'created_by'], 'boarding_obligation_actor_fk')
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['school_id', 'due_at'], 'boarding_obligation_due_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.boarding_reporting_evidence_immutable()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Boarding reporting evidence is immutable'
        USING ERRCODE = '23514';
END $$;

CREATE OR REPLACE FUNCTION public.boarding_policy_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_timezone_names WHERE name = NEW.timezone
    ) THEN
        RAISE EXCEPTION 'Invalid boarding policy timezone'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.boarding_obligation_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    policy boarding_reporting_policies%ROWTYPE;
    expected_due timestamptz;
BEGIN
    SELECT * INTO policy FROM boarding_reporting_policies
    WHERE school_id = NEW.school_id AND id = NEW.policy_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Boarding policy does not belong to school'
            USING ERRCODE = '23503';
    END IF;

    IF NOT policy.enabled
       OR NEW.reporting_date < policy.effective_from
       OR (policy.weekday_mask
           & (1 << (extract(isodow FROM NEW.reporting_date)::integer - 1))) = 0
    THEN
        RAISE EXCEPTION 'Date is outside boarding reporting policy'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1 FROM boarding_reporting_policies p
        WHERE p.school_id = NEW.school_id
          AND p.effective_from > policy.effective_from
          AND p.effective_from <= NEW.reporting_date
    ) THEN
        RAISE EXCEPTION 'A later boarding reporting policy applies'
            USING ERRCODE = '23514';
    END IF;

    expected_due := (
        (NEW.reporting_date + policy.deadline_day_offset::integer)
        + policy.deadline_time::time
    ) AT TIME ZONE policy.timezone;
    expected_due := expected_due + make_interval(mins => policy.grace_minutes);

    IF NEW.timezone IS NOT NULL AND NEW.timezone <> policy.timezone THEN
        RAISE EXCEPTION 'Boarding obligation timezone mismatch'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.due_at IS NOT NULL AND NEW.due_at <> expected_due THEN
        RAISE EXCEPTION 'Boarding obligation deadline mismatch'
            USING ERRCODE = '23514';
    END IF;

    NEW.timezone := policy.timezone;
    NEW.due_at := expected_due;
    RETURN NEW;
END $$;

CREATE TRIGGER boarding_policy_validate
BEFORE INSERT ON boarding_reporting_policies
FOR EACH ROW EXECUTE FUNCTION public.boarding_policy_validate();

CREATE TRIGGER boarding_policy_immutable
BEFORE UPDATE OR DELETE ON boarding_reporting_policies
FOR EACH ROW EXECUTE FUNCTION public.boarding_reporting_evidence_immutable();

CREATE TRIGGER boarding_obligation_validate
BEFORE INSERT ON boarding_daily_obligations
FOR EACH ROW EXECUTE FUNCTION public.boarding_obligation_validate();

CREATE TRIGGER boarding_obligation_immutable
BEFORE UPDATE OR DELETE ON boarding_daily_obligations
FOR EACH ROW EXECUTE FUNCTION public.boarding_reporting_evidence_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('boarding_daily_obligations')->exists()
            || DB::table('boarding_reporting_policies')->exists()) {
            throw new RuntimeException(
                'Retain boarding reporting evidence; use a reviewed forward migration.'
            );
        }

        Schema::drop('boarding_daily_obligations');
        Schema::drop('boarding_reporting_policies');

        DB::statement('DROP FUNCTION public.boarding_obligation_validate()');
        DB::statement('DROP FUNCTION public.boarding_policy_validate()');
        DB::statement('DROP FUNCTION public.boarding_reporting_evidence_immutable()');
    }
};
