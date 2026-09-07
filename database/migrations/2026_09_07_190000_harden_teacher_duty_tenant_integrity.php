<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Fail closed before replacing any relationship.
         *
         * Existing data must already satisfy the tenant relationship contract.
         * This migration deliberately does not repair, reassign or delete rows.
         */
        $periodWeekMismatch = DB::table('teacher_duty_periods as period')
            ->join(
                'academic_weeks as week',
                'week.id',
                '=',
                'period.academic_week_id'
            )
            ->whereNotNull('period.academic_week_id')
            ->whereColumn('period.school_id', '<>', 'week.school_id')
            ->exists();

        if ($periodWeekMismatch) {
            throw new RuntimeException(
                'Teacher Duty tenant hardening aborted: Academic Week tenant mismatch detected.'
            );
        }

        $assignmentPeriodMismatch = DB::table('teacher_duty_assignments as assignment')
            ->join(
                'teacher_duty_periods as period',
                'period.id',
                '=',
                'assignment.duty_period_id'
            )
            ->whereColumn('assignment.school_id', '<>', 'period.school_id')
            ->exists();

        if ($assignmentPeriodMismatch) {
            throw new RuntimeException(
                'Teacher Duty tenant hardening aborted: Duty Period tenant mismatch detected.'
            );
        }

        $assignmentTeacherMismatch = DB::table('teacher_duty_assignments as assignment')
            ->join(
                'teachers as teacher',
                'teacher.id',
                '=',
                'assignment.teacher_id'
            )
            ->whereColumn('assignment.school_id', '<>', 'teacher.school_id')
            ->exists();

        if ($assignmentTeacherMismatch) {
            throw new RuntimeException(
                'Teacher Duty tenant hardening aborted: Teacher tenant mismatch detected.'
            );
        }

        /*
         * PostgreSQL requires each referenced composite column set to be unique.
         */
        DB::statement(
            'CREATE UNIQUE INDEX teachers_school_id_id_unique
             ON teachers (school_id, id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX academic_weeks_school_id_id_unique
             ON academic_weeks (school_id, id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX teacher_duty_periods_school_id_id_unique
             ON teacher_duty_periods (school_id, id)'
        );

        /*
         * Replace the original single-column relationships.
         */
        DB::statement(
            'ALTER TABLE teacher_duty_periods
             DROP CONSTRAINT teacher_duty_periods_academic_week_foreign'
        );

        DB::statement(
            'ALTER TABLE teacher_duty_assignments
             DROP CONSTRAINT teacher_duty_assignments_period_foreign'
        );

        DB::statement(
            'ALTER TABLE teacher_duty_assignments
             DROP CONSTRAINT teacher_duty_assignments_teacher_foreign'
        );

        /*
         * Install tenant-composite relationships.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_periods
            ADD CONSTRAINT teacher_duty_periods_school_academic_week_foreign
            FOREIGN KEY (school_id, academic_week_id)
            REFERENCES academic_weeks (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_school_period_foreign
            FOREIGN KEY (school_id, duty_period_id)
            REFERENCES teacher_duty_periods (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_assignments
            ADD CONSTRAINT teacher_duty_assignments_school_teacher_foreign
            FOREIGN KEY (school_id, teacher_id)
            REFERENCES teachers (school_id, id)
            ON DELETE RESTRICT
            SQL
        );
    }

    public function down(): void
    {
        /*
         * Remove composite relationships first.
         */
        DB::statement(
            'ALTER TABLE teacher_duty_assignments
             DROP CONSTRAINT IF EXISTS teacher_duty_assignments_school_teacher_foreign'
        );

        DB::statement(
            'ALTER TABLE teacher_duty_assignments
             DROP CONSTRAINT IF EXISTS teacher_duty_assignments_school_period_foreign'
        );

        DB::statement(
            'ALTER TABLE teacher_duty_periods
             DROP CONSTRAINT IF EXISTS teacher_duty_periods_school_academic_week_foreign'
        );

        /*
         * Restore the original 6A.9F-B single-column relationships.
         */
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

        /*
         * Candidate keys are no longer needed after the composite FKs are gone.
         */
        DB::statement(
            'DROP INDEX IF EXISTS teacher_duty_periods_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS academic_weeks_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS teachers_school_id_id_unique'
        );
    }
};
