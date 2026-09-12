<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherDutyWeeklyReportingMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_weekly_reporting_tables_and_required_columns_exist(): void
    {
        $this->assertTrue(
            Schema::hasTable('teacher_duty_weekly_reports')
        );

        $this->assertTrue(
            Schema::hasTable('teacher_duty_weekly_report_history')
        );

        foreach ([
            'id',
            'school_id',
            'duty_period_id',
            'status',
            'summary',
            'highlights',
            'challenges',
            'recommendations',
            'evidence_snapshot',
            'created_by',
            'submitted_by',
            'submitted_at',
            'reviewed_by',
            'reviewed_at',
            'review_comment',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(
                    'teacher_duty_weekly_reports',
                    $column
                ),
                "Missing weekly report column: {$column}"
            );
        }

        foreach ([
            'id',
            'school_id',
            'weekly_report_id',
            'actor_user_id',
            'from_status',
            'to_status',
            'event',
            'comment',
            'evidence_snapshot',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(
                    'teacher_duty_weekly_report_history',
                    $column
                ),
                "Missing weekly history column: {$column}"
            );
        }
    }

    public function test_required_database_constraints_and_indexes_exist(): void
    {
        $constraints = DB::table('pg_constraint as c')
            ->join(
                'pg_class as t',
                't.oid',
                '=',
                'c.conrelid'
            )
            ->whereIn(
                't.relname',
                [
                    'teacher_duty_weekly_reports',
                    'teacher_duty_weekly_report_history',
                ]
            )
            ->pluck('c.conname')
            ->all();

        foreach ([
            'td_weekly_reports_school_foreign',
            'td_weekly_reports_school_period_foreign',
            'td_weekly_reports_school_created_by_foreign',
            'td_weekly_reports_school_submitted_by_foreign',
            'td_weekly_reports_school_reviewed_by_foreign',
            'td_weekly_reports_status_check',
            'td_weekly_reports_lifecycle_evidence_check',
            'td_weekly_reports_reviewer_separation_check',
            'td_weekly_report_history_school_foreign',
            'td_weekly_report_history_school_report_foreign',
            'td_weekly_report_history_school_actor_foreign',
        ] as $constraint) {
            $this->assertContains(
                $constraint,
                $constraints,
                "Missing database constraint: {$constraint}"
            );
        }

        $indexes = DB::table('pg_indexes')
            ->whereIn(
                'tablename',
                [
                    'teacher_duty_weekly_reports',
                    'teacher_duty_weekly_report_history',
                ]
            )
            ->pluck('indexname')
            ->all();

        foreach ([
            'td_weekly_reports_school_period_unique',
            'td_weekly_reports_school_id_id_unique',
            'td_weekly_reports_school_status_idx',
            'td_weekly_reports_school_status_submitted_idx',
            'td_weekly_report_history_school_report_created_idx',
        ] as $index) {
            $this->assertContains(
                $index,
                $indexes,
                "Missing database index: {$index}"
            );
        }
    }
}
