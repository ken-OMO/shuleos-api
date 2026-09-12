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
        Schema::create(
            'teacher_duty_weekly_reports',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('duty_period_id');

                $table->string('status', 30);

                $table->text('summary')->nullable();
                $table->text('highlights')->nullable();
                $table->text('challenges')->nullable();
                $table->text('recommendations')->nullable();

                $table->jsonb('evidence_snapshot')->nullable();

                $table->uuid('created_by');

                $table->uuid('submitted_by')->nullable();
                $table->timestampTz('submitted_at')->nullable();

                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->text('review_comment')->nullable();

                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_school_period_foreign
            FOREIGN KEY (school_id, duty_period_id)
            REFERENCES teacher_duty_periods (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_school_created_by_foreign
            FOREIGN KEY (school_id, created_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_school_submitted_by_foreign
            FOREIGN KEY (school_id, submitted_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_school_reviewed_by_foreign
            FOREIGN KEY (school_id, reviewed_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_status_check
            CHECK (
                status IN (
                    'draft',
                    'submitted',
                    'changes_requested',
                    'approved',
                    'rejected'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_lifecycle_evidence_check
            CHECK (
                (
                    status = 'draft'
                    AND submitted_by IS NULL
                    AND submitted_at IS NULL
                    AND evidence_snapshot IS NULL
                    AND reviewed_by IS NULL
                    AND reviewed_at IS NULL
                    AND review_comment IS NULL
                )
                OR
                (
                    status = 'submitted'
                    AND submitted_by IS NOT NULL
                    AND submitted_at IS NOT NULL
                    AND evidence_snapshot IS NOT NULL
                    AND reviewed_by IS NULL
                    AND reviewed_at IS NULL
                    AND review_comment IS NULL
                )
                OR
                (
                    status = 'changes_requested'
                    AND submitted_by IS NOT NULL
                    AND submitted_at IS NOT NULL
                    AND evidence_snapshot IS NOT NULL
                    AND reviewed_by IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND review_comment IS NOT NULL
                    AND btrim(review_comment) <> ''
                )
                OR
                (
                    status = 'approved'
                    AND submitted_by IS NOT NULL
                    AND submitted_at IS NOT NULL
                    AND evidence_snapshot IS NOT NULL
                    AND reviewed_by IS NOT NULL
                    AND reviewed_at IS NOT NULL
                )
                OR
                (
                    status = 'rejected'
                    AND submitted_by IS NOT NULL
                    AND submitted_at IS NOT NULL
                    AND evidence_snapshot IS NOT NULL
                    AND reviewed_by IS NOT NULL
                    AND reviewed_at IS NOT NULL
                    AND review_comment IS NOT NULL
                    AND btrim(review_comment) <> ''
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_reports
            ADD CONSTRAINT td_weekly_reports_reviewer_separation_check
            CHECK (
                reviewed_by IS NULL
                OR reviewed_by <> submitted_by
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX td_weekly_reports_school_period_unique
            ON teacher_duty_weekly_reports (
                school_id,
                duty_period_id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX td_weekly_reports_school_id_id_unique
            ON teacher_duty_weekly_reports (
                school_id,
                id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_weekly_reports_school_status_idx
            ON teacher_duty_weekly_reports (
                school_id,
                status
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_weekly_reports_school_status_submitted_idx
            ON teacher_duty_weekly_reports (
                school_id,
                status,
                submitted_at
            )
            SQL
        );

        Schema::create(
            'teacher_duty_weekly_report_history',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('weekly_report_id');
                $table->uuid('actor_user_id');

                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->string('event', 50);

                $table->text('comment')->nullable();
                $table->jsonb('evidence_snapshot')->nullable();

                $table->timestampTz('created_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_report_history
            ADD CONSTRAINT td_weekly_report_history_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_report_history
            ADD CONSTRAINT td_weekly_report_history_school_report_foreign
            FOREIGN KEY (school_id, weekly_report_id)
            REFERENCES teacher_duty_weekly_reports (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_weekly_report_history
            ADD CONSTRAINT td_weekly_report_history_school_actor_foreign
            FOREIGN KEY (school_id, actor_user_id)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX td_weekly_report_history_school_report_created_idx
            ON teacher_duty_weekly_report_history (
                school_id,
                weekly_report_id,
                created_at
            )
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'teacher_duty_weekly_report_history'
        );

        Schema::dropIfExists(
            'teacher_duty_weekly_reports'
        );
    }
};
