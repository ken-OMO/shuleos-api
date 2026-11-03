<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Boarding category revisions require PostgreSQL.');
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");

        DB::unprepared(<<<'SQL'
ALTER TABLE boarding_occurrence_categories
ADD COLUMN IF NOT EXISTS lock_version bigint NOT NULL DEFAULT 1
CHECK (lock_version > 0);

DROP TRIGGER IF EXISTS boarding_category_revision_validate
ON boarding_occurrence_categories;

CREATE OR REPLACE FUNCTION public.boarding_category_revision_validate()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.category_name, NEW.active)
       IS DISTINCT FROM (OLD.category_name, OLD.active) THEN
        NEW.lock_version := OLD.lock_version + 1;
    ELSE
        NEW.lock_version := OLD.lock_version;
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER boarding_category_revision_validate
BEFORE UPDATE ON boarding_occurrence_categories
FOR EACH ROW EXECUTE FUNCTION public.boarding_category_revision_validate();

SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Retain boarding category revisions; use a reviewed forward migration.'
        );
    }
};
