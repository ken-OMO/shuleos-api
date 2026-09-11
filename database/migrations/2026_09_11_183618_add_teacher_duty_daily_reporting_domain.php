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
        /*
         * The frozen clarification requires every School to have exactly one
         * persistent school_settings row.
         *
         * Existing rows are preserved. Missing rows rely entirely on the
         * existing database defaults for unrelated settings.
         */
        DB::statement(
            <<<'SQL'
            INSERT INTO school_settings (school_id)
            SELECT schools.id
            FROM schools
            LEFT JOIN school_settings
                ON school_settings.school_id = schools.id
            WHERE school_settings.school_id IS NULL
            ORDER BY schools.id
            SQL
        );

        /*
         * Teacher Duty daily-report deadline configuration.
         *
         * Time is interpreted in schools.timezone by the domain service.
         */
        Schema::table(
            'school_settings',
            function (Blueprint $table): void {
                $table->time(
                    'teacher_duty_report_deadline_time'
                )->default('17:00:00');

                $table->integer(
                    'teacher_duty_report_grace_minutes'
                )->default(120);
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE school_settings
            ADD CONSTRAINT school_settings_teacher_duty_grace_nonnegative_check
            CHECK (teacher_duty_report_grace_minutes >= 0)
            SQL
        );

        Schema::create(
            'teacher_duty_daily_reports',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('duty_period_id');

                $table->date('report_date');

                $table->string('status', 20);

                $table->text('summary')->nullable();

                $table->timestampTz('deadline_at');

                $table->uuid('created_by');

                $table->uuid('submitted_by')->nullable();
                $table->timestampTz('submitted_at')->nullable();

                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_school_period_foreign
            FOREIGN KEY (school_id, duty_period_id)
            REFERENCES teacher_duty_periods (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_school_created_by_foreign
            FOREIGN KEY (school_id, created_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_school_submitted_by_foreign
            FOREIGN KEY (school_id, submitted_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_status_check
            CHECK (
                status IN (
                    'draft',
                    'submitted'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_reports
            ADD CONSTRAINT td_daily_reports_submission_evidence_check
            CHECK (
                (
                    status = 'draft'
                    AND submitted_by IS NULL
                    AND submitted_at IS NULL
                )
                OR
                (
                    status = 'submitted'
                    AND submitted_by IS NOT NULL
                    AND submitted_at IS NOT NULL
                )
            )
            SQL
        );

        /*
         * Frozen report identity.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX td_daily_reports_school_period_date_unique
            ON teacher_duty_daily_reports (
                school_id,
                duty_period_id,
                report_date
            )
            SQL
        );

        /*
         * Required for tenant-safe composite references from report history.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX td_daily_reports_school_id_id_unique
            ON teacher_duty_daily_reports (
                school_id,
                id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_daily_reports_school_date_idx
            ON teacher_duty_daily_reports (
                school_id,
                report_date
            )
            SQL
        );

        /*
         * This explicit index is retained because it is part of the frozen
         * database contract even though the unique report-identity index
         * begins with the same columns.
         */
        DB::statement(
            <<<'SQL'
            CREATE INDEX td_daily_reports_school_period_date_idx
            ON teacher_duty_daily_reports (
                school_id,
                duty_period_id,
                report_date
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_daily_reports_school_status_deadline_idx
            ON teacher_duty_daily_reports (
                school_id,
                status,
                deadline_at
            )
            SQL
        );

        Schema::create(
            'teacher_duty_daily_report_history',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('daily_report_id');
                $table->uuid('actor_user_id');

                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('event', 50);

                $table->timestampTz('created_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_report_history
            ADD CONSTRAINT td_daily_report_history_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_report_history
            ADD CONSTRAINT td_daily_report_history_school_report_foreign
            FOREIGN KEY (school_id, daily_report_id)
            REFERENCES teacher_duty_daily_reports (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_daily_report_history
            ADD CONSTRAINT td_daily_report_history_school_actor_foreign
            FOREIGN KEY (school_id, actor_user_id)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_daily_report_history_school_report_created_idx
            ON teacher_duty_daily_report_history (
                school_id,
                daily_report_id,
                created_at
            )
            SQL
        );
    }

    public function down(): void
    {
        /*
         * History depends on reports, so it must be removed first.
         */
        Schema::dropIfExists(
            'teacher_duty_daily_report_history'
        );

        Schema::dropIfExists(
            'teacher_duty_daily_reports'
        );

        /*
         * Provisioned school_settings rows are deliberately preserved.
         * Only the Teacher Duty reporting configuration is rolled back.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE school_settings
            DROP CONSTRAINT IF EXISTS school_settings_teacher_duty_grace_nonnegative_check
            SQL
        );

        Schema::table(
            'school_settings',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'teacher_duty_report_deadline_time',
                    'teacher_duty_report_grace_minutes',
                ]);
            }
        );
    }
};
