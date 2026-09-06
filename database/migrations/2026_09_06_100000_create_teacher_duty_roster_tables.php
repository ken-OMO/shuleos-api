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
        Schema::create('teacher_duty_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('academic_week_id')->nullable();

            $table->date('start_date');
            $table->date('end_date');

            $table->boolean('active')->default(true);

            $table->uuid('created_by');
            $table->uuid('ended_by')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_academic_week_foreign
            FOREIGN KEY (academic_week_id)
            REFERENCES academic_weeks (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_school_created_by_foreign
            FOREIGN KEY (school_id, created_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_school_ended_by_foreign
            FOREIGN KEY (school_id, ended_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_dates_check
            CHECK (end_date >= start_date)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_lifecycle_check
            CHECK (
                (
                    active IS TRUE
                    AND ended_by IS NULL
                    AND ended_at IS NULL
                    AND end_reason IS NULL
                )
                OR
                (
                    active IS FALSE
                    AND ended_by IS NOT NULL
                    AND ended_at IS NOT NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_periods_school_dates_idx
            ON teacher_duty_periods (
                school_id,
                start_date,
                end_date
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_periods_school_active_idx
            ON teacher_duty_periods (
                school_id,
                active
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_periods_school_academic_week_idx
            ON teacher_duty_periods (
                school_id,
                academic_week_id
            )
            SQL
        );

        Schema::create('teacher_duty_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('duty_period_id');
            $table->uuid('teacher_id');

            $table->boolean('active')->default(true);

            $table->uuid('assigned_by');
            $table->uuid('ended_by')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_period_foreign
            FOREIGN KEY (duty_period_id)
            REFERENCES teacher_duty_periods (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_teacher_foreign
            FOREIGN KEY (teacher_id)
            REFERENCES teachers (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_school_assigned_by_foreign
            FOREIGN KEY (school_id, assigned_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_school_ended_by_foreign
            FOREIGN KEY (school_id, ended_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_lifecycle_check
            CHECK (
                (
                    active IS TRUE
                    AND ended_by IS NULL
                    AND ended_at IS NULL
                    AND end_reason IS NULL
                )
                OR
                (
                    active IS FALSE
                    AND ended_by IS NOT NULL
                    AND ended_at IS NOT NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX teacher_duty_assignments_active_identity_unique
            ON teacher_duty_assignments (
                school_id,
                duty_period_id,
                teacher_id
            )
            WHERE active IS TRUE
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_assignments_school_period_active_idx
            ON teacher_duty_assignments (
                school_id,
                duty_period_id,
                active
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_assignments_school_teacher_idx
            ON teacher_duty_assignments (
                school_id,
                teacher_id
            )
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_duty_assignments');
        Schema::dropIfExists('teacher_duty_periods');
    }
};
