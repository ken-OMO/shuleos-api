<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyDailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeacherDutyDailyReportServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_open_report_creates_draft_with_server_owned_fields_and_history(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $this->assertSame($school->id, $report->school_id);
        $this->assertSame($periodId, $report->duty_period_id);
        $this->assertSame('2026-09-09', $report->report_date->toDateString());
        $this->assertSame('draft', $report->status);
        $this->assertNull($report->summary);
        $this->assertSame($actor->id, $report->created_by);
        $this->assertNull($report->submitted_by);
        $this->assertNull($report->submitted_at);

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'school_id' => $school->id,
                'daily_report_id' => $report->id,
                'actor_user_id' => $actor->id,
                'from_status' => null,
                'to_status' => 'draft',
                'event' => 'created',
            ]
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $report->id)
                ->count()
        );
    }

    public function test_open_report_computes_deadline_in_school_timezone_with_grace(): void
    {
        $school = $this->school('Africa/Nairobi');

        $this->settings(
            $school,
            '17:00:00',
            120
        );

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $this->assertSame(
            '2026-09-09 19:00:00',
            $report->deadline_at
                ->copy()
                ->setTimezone('Africa/Nairobi')
                ->format('Y-m-d H:i:s')
        );
    }

    public function test_open_report_accepts_inclusive_period_boundaries(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $start = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-07',
            $actor->id
        );

        $end = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-11',
            $actor->id
        );

        $this->assertSame(
            '2026-09-07',
            $start->report_date->toDateString()
        );

        $this->assertSame(
            '2026-09-11',
            $end->report_date->toDateString()
        );
    }

    public function test_open_report_rejects_non_strict_report_date(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectValidationFailure(
            'report_date',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->service()->openReport(
                    $school->id,
                    $periodId,
                    '2026-9-9',
                    $actor->id
                );
            }
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_rejects_date_outside_period(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectValidationFailure(
            'report_date',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->service()->openReport(
                    $school->id,
                    $periodId,
                    '2026-09-12',
                    $actor->id
                );
            }
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_fails_closed_for_period_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $this->settings($schoolA);
        $this->settings($schoolB);

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodB = $this->period(
            $schoolB,
            $actorB
        );

        $this->expectValidationFailure(
            'period_id',
            function () use (
                $schoolA,
                $periodB,
                $actorA
            ): void {
                $this->service()->openReport(
                    $schoolA->id,
                    $periodB,
                    '2026-09-09',
                    $actorA->id
                );
            }
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_fails_closed_for_actor_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $this->settings($schoolA);

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $this->expectValidationFailure(
            'actor',
            function () use (
                $schoolA,
                $periodA,
                $actorB
            ): void {
                $this->service()->openReport(
                    $schoolA->id,
                    $periodA,
                    '2026-09-09',
                    $actorB->id
                );
            }
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_rejects_inactive_deleted_and_suspended_actors(): void
    {
        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $changes
        ) {
            $school = $this->school();
            $this->settings($school);

            $creator = $this->user($school);
            $periodId = $this->period(
                $school,
                $creator
            );

            $actor = $this->user($school);

            DB::table('users')
                ->where('id', $actor->id)
                ->update($changes);

            $this->expectValidationFailure(
                'actor',
                function () use (
                    $school,
                    $periodId,
                    $actor
                ): void {
                    $this->service()->openReport(
                        $school->id,
                        $periodId,
                        '2026-09-09',
                        $actor->id
                    );
                }
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_fails_closed_when_persistent_settings_are_missing(): void
    {
        $school = $this->school();

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectValidationFailure(
            'school_settings',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->service()->openReport(
                    $school->id,
                    $periodId,
                    '2026-09-09',
                    $actor->id
                );
            }
        );

        $this->assertDatabaseMissing(
            'school_settings',
            [
                'school_id' => $school->id,
            ]
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );
    }

    public function test_open_report_is_idempotent_and_does_not_duplicate_history(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $first = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $second = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_reports')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->whereDate('report_date', '2026-09-09')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $first->id)
                ->count()
        );
    }

    public function test_existing_report_preserves_deadline_snapshot_after_settings_change(): void
    {
        $school = $this->school();

        $this->settings(
            $school,
            '17:00:00',
            120
        );

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $first = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $originalDeadline = $first
            ->deadline_at
            ->toISOString();

        DB::table('school_settings')
            ->where('school_id', $school->id)
            ->update([
                'teacher_duty_report_deadline_time' => '15:00:00',
                'teacher_duty_report_grace_minutes' => 0,
            ]);

        $second = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            $originalDeadline,
            $second->deadline_at->toISOString()
        );
    }

    public function test_state_returns_not_started_before_deadline_without_creating_report(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:59:59',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'NOT_STARTED',
                $state['state']
            );
            $this->assertFalse($state['late']);
            $this->assertNull($state['submitted_at']);

            $this->assertSame(
                '2026-09-09 19:00:00',
                $state['deadline_at']
                    ->setTimezone('Africa/Nairobi')
                    ->format('Y-m-d H:i:s')
            );

            $this->assertSame(
                0,
                DB::table('teacher_duty_daily_reports')->count()
            );

            $this->assertSame(
                0,
                DB::table(
                    'teacher_duty_daily_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_returns_overdue_for_no_report_exactly_at_deadline(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'OVERDUE',
                $state['state']
            );

            $this->assertSame(
                0,
                DB::table('teacher_duty_daily_reports')->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_returns_draft_before_deadline(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:59:59',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame('DRAFT', $state['state']);
            $this->assertFalse($state['late']);

            $this->assertSame(
                1,
                DB::table(
                    'teacher_duty_daily_report_history'
                )
                    ->where(
                        'daily_report_id',
                        $report->id
                    )
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_returns_overdue_for_draft_exactly_at_deadline(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'OVERDUE',
                $state['state']
            );

            $report->refresh();

            $this->assertSame(
                'draft',
                $report->status
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_returns_submitted_and_not_late_when_submitted_exactly_at_deadline(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            );

            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'SUBMITTED',
                $state['state']
            );

            $this->assertFalse($state['late']);
            $this->assertNotNull(
                $state['submitted_at']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_marks_submission_after_deadline_as_late(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:01',
                'Africa/Nairobi'
            )
        );

        try {
            $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            );

            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'SUBMITTED',
                $state['state']
            );

            $this->assertTrue($state['late']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_uses_persisted_deadline_snapshot_for_existing_report(): void
    {
        $school = $this->school();

        $this->settings(
            $school,
            '17:00:00',
            120
        );

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        DB::table('school_settings')
            ->where('school_id', $school->id)
            ->update([
                'teacher_duty_report_deadline_time' => '15:00:00',
                'teacher_duty_report_grace_minutes' => 0,
            ]);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'DRAFT',
                $state['state']
            );

            $this->assertSame(
                $report->deadline_at->toISOString(),
                $state['deadline_at']->toISOString()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_state_fails_closed_for_actor_period_and_settings_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $this->settings($schoolA);
        $this->settings($schoolB);

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $periodB = $this->period(
            $schoolB,
            $actorB
        );

        $this->expectValidationFailure(
            'actor',
            function () use (
                $schoolA,
                $periodA,
                $actorB
            ): void {
                $this->service()->state(
                    $schoolA->id,
                    $periodA,
                    '2026-09-09',
                    $actorB->id
                );
            }
        );

        $this->expectValidationFailure(
            'period_id',
            function () use (
                $schoolA,
                $periodB,
                $actorA
            ): void {
                $this->service()->state(
                    $schoolA->id,
                    $periodB,
                    '2026-09-09',
                    $actorA->id
                );
            }
        );

        DB::table('school_settings')
            ->where('school_id', $schoolA->id)
            ->delete();

        $this->expectValidationFailure(
            'school_settings',
            function () use (
                $schoolA,
                $periodA,
                $actorA
            ): void {
                $this->service()->state(
                    $schoolA->id,
                    $periodA,
                    '2026-09-09',
                    $actorA->id
                );
            }
        );
    }

    public function test_update_draft_changes_only_summary_and_creates_no_history(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $deadline = $report->deadline_at->toISOString();
        $createdBy = $report->created_by;

        $updated = $this->service()->updateDraft(
            $school->id,
            $report->id,
            '  School day completed successfully.  ',
            $actor->id
        );

        $this->assertSame(
            'School day completed successfully.',
            $updated->summary
        );

        $this->assertSame('draft', $updated->status);
        $this->assertSame($createdBy, $updated->created_by);
        $this->assertSame(
            $deadline,
            $updated->deadline_at->toISOString()
        );
        $this->assertNull($updated->submitted_by);
        $this->assertNull($updated->submitted_at);

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $report->id)
                ->count()
        );
    }

    public function test_update_draft_normalizes_blank_summary_to_null(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $updated = $this->service()->updateDraft(
            $school->id,
            $report->id,
            '   ',
            $actor->id
        );

        $this->assertNull($updated->summary);
    }

    public function test_update_draft_fails_closed_for_report_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $this->settings($schoolA);
        $this->settings($schoolB);

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodB = $this->period($schoolB, $actorB);

        $reportB = $this->service()->openReport(
            $schoolB->id,
            $periodB,
            '2026-09-09',
            $actorB->id
        );

        $this->expectValidationFailure(
            'report_id',
            function () use (
                $schoolA,
                $reportB,
                $actorA
            ): void {
                $this->service()->updateDraft(
                    $schoolA->id,
                    $reportB->id,
                    'Tampered',
                    $actorA->id
                );
            }
        );

        $reportB->refresh();

        $this->assertNull($reportB->summary);
    }

    public function test_submit_report_sets_server_owned_evidence_and_history(): void
    {
        $school = $this->school();
        $this->settings($school);

        $creator = $this->user($school);
        $submitter = $this->user($school);

        $periodId = $this->period($school, $creator);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $creator->id
        );

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame(
            $submitter->id,
            $submitted->submitted_by
        );
        $this->assertNotNull($submitted->submitted_at);

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'school_id' => $school->id,
                'daily_report_id' => $report->id,
                'actor_user_id' => $submitter->id,
                'from_status' => 'draft',
                'to_status' => 'submitted',
                'event' => 'submitted',
            ]
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $report->id)
                ->count()
        );
    }

    public function test_submitted_report_cannot_be_updated_or_submitted_again(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $submittedAt = $submitted->submitted_at->toISOString();

        $this->expectValidationFailure(
            'report_id',
            function () use (
                $school,
                $report,
                $actor
            ): void {
                $this->service()->updateDraft(
                    $school->id,
                    $report->id,
                    'Not allowed',
                    $actor->id
                );
            }
        );

        $this->expectValidationFailure(
            'report_id',
            function () use (
                $school,
                $report,
                $actor
            ): void {
                $this->service()->submitReport(
                    $school->id,
                    $report->id,
                    $actor->id
                );
            }
        );

        $submitted->refresh();

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame(
            $submittedAt,
            $submitted->submitted_at->toISOString()
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $report->id)
                ->count()
        );
    }

    public function test_submit_report_fails_closed_for_report_and_actor_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $this->settings($schoolA);
        $this->settings($schoolB);

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period($schoolA, $actorA);

        $reportA = $this->service()->openReport(
            $schoolA->id,
            $periodA,
            '2026-09-09',
            $actorA->id
        );

        $this->expectValidationFailure(
            'actor',
            function () use (
                $schoolA,
                $reportA,
                $actorB
            ): void {
                $this->service()->submitReport(
                    $schoolA->id,
                    $reportA->id,
                    $actorB->id
                );
            }
        );

        $this->expectValidationFailure(
            'report_id',
            function () use (
                $schoolB,
                $reportA,
                $actorB
            ): void {
                $this->service()->submitReport(
                    $schoolB->id,
                    $reportA->id,
                    $actorB->id
                );
            }
        );

        $reportA->refresh();

        $this->assertSame('draft', $reportA->status);
        $this->assertNull($reportA->submitted_by);
        $this->assertNull($reportA->submitted_at);
    }

    public function test_report_can_be_submitted_with_zero_occurrences(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->whereDate('occurrence_date', '2026-09-09')
                ->count()
        );

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame(
            'submitted',
            $submitted->status
        );

        $this->assertNotNull(
            $submitted->submitted_at
        );
    }

    public function test_occurrence_count_does_not_complete_or_auto_submit_report(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        $categoryId = $this->occurrenceCategory(
            $school,
            $actor
        );

        $this->occurrence(
            $school,
            $periodId,
            $categoryId,
            $actor,
            'First occurrence.'
        );

        $this->occurrence(
            $school,
            $periodId,
            $categoryId,
            $actor,
            'Second occurrence.'
        );

        $report->refresh();

        $this->assertSame(
            'draft',
            $report->status
        );

        $this->assertNull(
            $report->submitted_by
        );

        $this->assertNull(
            $report->submitted_at
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->whereDate('occurrence_date', '2026-09-09')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $report->id)
                ->count()
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:59:59',
                'Africa/Nairobi'
            )
        );

        try {
            $state = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'DRAFT',
                $state['state']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_overdue_draft_remains_editable_and_can_be_submitted_late(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:01',
                'Africa/Nairobi'
            )
        );

        try {
            $beforeUpdate = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'OVERDUE',
                $beforeUpdate['state']
            );

            $updated = $this->service()->updateDraft(
                $school->id,
                $report->id,
                'Late report completed.',
                $actor->id
            );

            $this->assertSame(
                'draft',
                $updated->status
            );

            $this->assertSame(
                'Late report completed.',
                $updated->summary
            );

            $submitted = $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            );

            $this->assertSame(
                'submitted',
                $submitted->status
            );

            $afterSubmit = $this->service()->state(
                $school->id,
                $periodId,
                '2026-09-09',
                $actor->id
            );

            $this->assertSame(
                'SUBMITTED',
                $afterSubmit['state']
            );

            $this->assertTrue(
                $afterSubmit['late']
            );

            $this->assertSame(
                2,
                DB::table(
                    'teacher_duty_daily_report_history'
                )
                    ->where(
                        'daily_report_id',
                        $report->id
                    )
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_existing_reporting_operations_fail_closed_when_persistent_settings_are_missing(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            '2026-09-09',
            $actor->id
        );

        DB::table('school_settings')
            ->where('school_id', $school->id)
            ->delete();

        $this->expectValidationFailure(
            'school_settings',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->service()->openReport(
                    $school->id,
                    $periodId,
                    '2026-09-09',
                    $actor->id
                );
            }
        );

        $this->expectValidationFailure(
            'school_settings',
            function () use (
                $school,
                $report,
                $actor
            ): void {
                $this->service()->updateDraft(
                    $school->id,
                    $report->id,
                    'Must fail closed.',
                    $actor->id
                );
            }
        );

        $this->expectValidationFailure(
            'school_settings',
            function () use (
                $school,
                $report,
                $actor
            ): void {
                $this->service()->submitReport(
                    $school->id,
                    $report->id,
                    $actor->id
                );
            }
        );

        $report->refresh();

        $this->assertSame('draft', $report->status);
        $this->assertNull($report->submitted_by);
        $this->assertNull($report->submitted_at);
        $this->assertNull($report->summary);
    }

    private function occurrenceCategory(
        School $school,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'teacher_duty_occurrence_categories'
        )->insert([
            'id' => $id,
            'school_id' => $school->id,
            'code' => 'daily_reporting_'.Str::lower(
                Str::random(8)
            ),
            'name' => 'Daily Reporting Test',
            'description' => null,
            'is_canonical' => false,
            'display_order' => 90,
            'active' => true,
            'created_by' => $actor->id,
            'deactivated_by' => null,
            'deactivated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function occurrence(
        School $school,
        string $periodId,
        string $categoryId,
        User $actor,
        string $description
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_occurrences')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'occurrence_category_id' => $categoryId,
            'occurrence_date' => '2026-09-09',
            'occurrence_time' => '10:15:00',
            'description' => $description,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function service(): TeacherDutyDailyReportService
    {
        return app(TeacherDutyDailyReportService::class);
    }

    private function expectValidationFailure(
        string $field,
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation failure for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    private function settings(
        School $school,
        string $deadline = '17:00:00',
        int $graceMinutes = 120
    ): void {
        DB::table('school_settings')->insert([
            'school_id' => $school->id,
            'teacher_duty_report_deadline_time' => $deadline,
            'teacher_duty_report_grace_minutes' => $graceMinutes,
        ]);
    }

    private function period(
        School $school,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_periods')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'academic_week_id' => null,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'active' => true,
            'created_by' => $actor->id,
            'ended_by' => null,
            'ended_at' => null,
            'end_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function school(
        string $timezone = 'Africa/Nairobi'
    ): School {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Daily Reporting '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'DRS-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'DRS',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => $timezone,
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Daily Reporting '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty',
            'username' => 'daily_reporting_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => 'test-password-hash',
            'active' => true,
            'is_deleted' => false,
            'suspended_at' => null,
        ]);
    }
}
