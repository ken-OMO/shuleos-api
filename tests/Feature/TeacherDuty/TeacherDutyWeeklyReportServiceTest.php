<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\TeacherDutyWeeklyReport;
use App\Models\TeacherDutyWeeklyReportHistory;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeacherDutyWeeklyReportServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_open_report_creates_one_draft_report_for_the_duty_period(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $this->assertSame($school->id, $report->school_id);
        $this->assertSame($periodId, $report->duty_period_id);
        $this->assertSame('draft', $report->status);
        $this->assertSame($actor->id, $report->created_by);

        $this->assertNull($report->summary);
        $this->assertNull($report->highlights);
        $this->assertNull($report->challenges);
        $this->assertNull($report->recommendations);

        $this->assertNull($report->evidence_snapshot);
        $this->assertNull($report->submitted_by);
        $this->assertNull($report->submitted_at);
        $this->assertNull($report->reviewed_by);
        $this->assertNull($report->reviewed_at);
        $this->assertNull($report->review_comment);

        $this->assertDatabaseHas(
            'teacher_duty_weekly_report_history',
            [
                'school_id' => $school->id,
                'weekly_report_id' => $report->id,
                'actor_user_id' => $actor->id,
                'from_status' => null,
                'to_status' => 'draft',
                'event' => 'created',
            ]
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->count()
        );
    }

    public function test_open_report_returns_existing_report_for_the_same_period(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $first = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $second = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_reports')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $first->id)
                ->where('event', 'created')
                ->count()
        );
    }

    public function test_draft_narrative_can_be_updated_while_period_is_active(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $updated = $this->service()->updateReport(
            $school->id,
            $report->id,
            'Weekly summary',
            'Strong punctuality',
            'Two discipline cases',
            'Increase gate supervision',
            $actor->id
        );

        $this->assertSame('draft', $updated->status);
        $this->assertSame('Weekly summary', $updated->summary);
        $this->assertSame('Strong punctuality', $updated->highlights);
        $this->assertSame('Two discipline cases', $updated->challenges);
        $this->assertSame(
            'Increase gate supervision',
            $updated->recommendations
        );

        $this->assertDatabaseHas(
            'teacher_duty_periods',
            [
                'id' => $periodId,
                'school_id' => $school->id,
                'active' => true,
                'ended_by' => null,
            ]
        );
    }

    public function test_draft_cannot_be_submitted_until_period_is_authoritatively_ended(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $this->expectValidationFailure(
            'period_id',
            fn () => $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            )
        );

        $report->refresh();

        $this->assertSame('draft', $report->status);
        $this->assertNull($report->submitted_by);
        $this->assertNull($report->submitted_at);
        $this->assertNull($report->evidence_snapshot);
    }

    public function test_ended_period_can_be_submitted_with_incomplete_daily_reporting(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame($actor->id, $submitted->submitted_by);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertIsArray($submitted->evidence_snapshot);

        $snapshot = $submitted->evidence_snapshot;

        $this->assertSame(1, $snapshot['snapshot_version']);
        $this->assertSame(
            '2026-09-07',
            $snapshot['duty_period_start_date']
        );
        $this->assertSame(
            '2026-09-11',
            $snapshot['duty_period_end_date']
        );

        $this->assertSame(
            5,
            $snapshot['expected_daily_report_count']
        );

        $this->assertSame(
            5,
            $snapshot['not_started_daily_report_count']
                + $snapshot['draft_daily_report_count']
                + $snapshot['overdue_daily_report_count']
                + $snapshot['submitted_daily_report_count']
        );

        $this->assertLessThanOrEqual(
            $snapshot['submitted_daily_report_count'],
            $snapshot['late_submitted_daily_report_count']
        );

        $this->assertSame(
            0,
            $snapshot['total_occurrence_count']
        );

        $this->assertSame(
            [],
            $snapshot['occurrence_category_breakdown']
        );

        $this->assertNotEmpty(
            $snapshot['snapshot_generated_at']
        );

        $this->assertDatabaseHas(
            'teacher_duty_weekly_report_history',
            [
                'school_id' => $school->id,
                'weekly_report_id' => $report->id,
                'actor_user_id' => $actor->id,
                'from_status' => 'draft',
                'to_status' => 'submitted',
                'event' => 'submitted',
            ]
        );

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'submitted')
            ->first();

        $this->assertNotNull($history);

        $historySnapshot = json_decode(
            $history->evidence_snapshot,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $snapshot,
            $historySnapshot
        );
    }

    public function test_submission_snapshot_uses_frozen_daily_presentation_state_semantics(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::create(
                2026,
                9,
                10,
                12,
                0,
                0,
                'Africa/Nairobi'
            )
        );

        try {
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
                $actor->id
            );

            /*
             * 2026-09-07:
             * Missing after deadline => OVERDUE.
             */

            /*
             * 2026-09-08:
             * Existing draft after deadline => OVERDUE.
             */
            $this->dailyReport(
                $school,
                $periodId,
                $actor,
                '2026-09-08',
                'draft',
                '2026-09-08 19:00:00+03',
                null
            );

            /*
             * 2026-09-09:
             * Submitted before deadline => SUBMITTED, not late.
             */
            $this->dailyReport(
                $school,
                $periodId,
                $actor,
                '2026-09-09',
                'submitted',
                '2026-09-09 19:00:00+03',
                '2026-09-09 18:30:00+03'
            );

            /*
             * 2026-09-10:
             * Draft before today's deadline => DRAFT.
             */
            $this->dailyReport(
                $school,
                $periodId,
                $actor,
                '2026-09-10',
                'draft',
                '2026-09-10 19:00:00+03',
                null
            );

            /*
             * 2026-09-11:
             * Missing and deadline still in future => NOT_STARTED.
             */

            /*
             * Add a second submitted row by replacing the 2026-09-07
             * missing obligation with a late-submitted report.
             *
             * This leaves the five final states as:
             * 07 SUBMITTED late
             * 08 OVERDUE
             * 09 SUBMITTED on-time
             * 10 DRAFT
             * 11 NOT_STARTED
             */
            $this->dailyReport(
                $school,
                $periodId,
                $actor,
                '2026-09-07',
                'submitted',
                '2026-09-07 19:00:00+03',
                '2026-09-07 20:15:00+03'
            );

            DB::table('teacher_duty_periods')
                ->where('id', $periodId)
                ->update([
                    'active' => false,
                    'ended_by' => $actor->id,
                    'ended_at' => now(),
                    'end_reason' => 'Duty period ended for review.',
                    'updated_at' => now(),
                ]);

            $submitted = $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            );

            $snapshot = $submitted->evidence_snapshot;

            $this->assertSame(
                5,
                $snapshot['expected_daily_report_count']
            );

            $this->assertSame(
                1,
                $snapshot['not_started_daily_report_count']
            );

            $this->assertSame(
                1,
                $snapshot['draft_daily_report_count']
            );

            $this->assertSame(
                1,
                $snapshot['overdue_daily_report_count']
            );

            $this->assertSame(
                2,
                $snapshot['submitted_daily_report_count']
            );

            $this->assertSame(
                1,
                $snapshot['late_submitted_daily_report_count']
            );

            $this->assertSame(
                $snapshot['expected_daily_report_count'],
                $snapshot['not_started_daily_report_count']
                    + $snapshot['draft_daily_report_count']
                    + $snapshot['overdue_daily_report_count']
                    + $snapshot['submitted_daily_report_count']
            );

            $this->assertLessThanOrEqual(
                $snapshot['submitted_daily_report_count'],
                $snapshot['late_submitted_daily_report_count']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_submission_snapshot_aggregates_occurrences_without_mutating_them(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $pastoralCategoryId = $this->occurrenceCategory(
            $school,
            $actor,
            'pastoral_support',
            'Pastoral Support'
        );

        $medicalSupportCategoryId = $this->occurrenceCategory(
            $school,
            $actor,
            'medical_support',
            'Medical Support'
        );

        $occurrenceOne = $this->occurrence(
            $school,
            $periodId,
            $pastoralCategoryId,
            $actor,
            '2026-09-08',
            'Late arrival at the gate.'
        );

        $occurrenceTwo = $this->occurrence(
            $school,
            $periodId,
            $pastoralCategoryId,
            $actor,
            '2026-09-09',
            'Uniform issue recorded.'
        );

        $occurrenceThree = $this->occurrence(
            $school,
            $periodId,
            $medicalSupportCategoryId,
            $actor,
            '2026-09-10',
            'Learner received first aid.'
        );

        $before = DB::table('teacher_duty_occurrences')
            ->whereIn(
                'id',
                [
                    $occurrenceOne,
                    $occurrenceTwo,
                    $occurrenceThree,
                ]
            )
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $this->assertSame(
            3,
            $snapshot['total_occurrence_count']
        );

        $this->assertSame(
            [
                [
                    'category_id' => $medicalSupportCategoryId,
                    'category_code' => 'medical_support',
                    'category_name' => 'Medical Support',
                    'occurrence_count' => 1,
                ],
                [
                    'category_id' => $pastoralCategoryId,
                    'category_code' => 'pastoral_support',
                    'category_name' => 'Pastoral Support',
                    'occurrence_count' => 2,
                ],
            ],
            $snapshot['occurrence_category_breakdown']
        );

        $after = DB::table('teacher_duty_occurrences')
            ->whereIn(
                'id',
                [
                    $occurrenceOne,
                    $occurrenceTwo,
                    $occurrenceThree,
                ]
            )
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $this->assertSame(
            $before,
            $after
        );
    }

    public function test_reviewer_can_request_changes_with_meaningful_comment(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $reviewed = $this->service()->reviewReport(
            $school->id,
            $report->id,
            'changes_requested',
            'Please clarify the reported challenges.',
            $reviewer->id
        );

        $this->assertSame(
            'changes_requested',
            $reviewed->status
        );

        $this->assertSame(
            $reviewer->id,
            $reviewed->reviewed_by
        );

        $this->assertNotNull(
            $reviewed->reviewed_at
        );

        $this->assertSame(
            'Please clarify the reported challenges.',
            $reviewed->review_comment
        );

        $this->assertSame(
            $snapshot,
            $reviewed->evidence_snapshot
        );

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'changes_requested')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame(
            $school->id,
            $history->school_id
        );
        $this->assertSame(
            $reviewer->id,
            $history->actor_user_id
        );
        $this->assertSame(
            'submitted',
            $history->from_status
        );
        $this->assertSame(
            'changes_requested',
            $history->to_status
        );
        $this->assertSame(
            'Please clarify the reported challenges.',
            $history->comment
        );

        $this->assertSame(
            $snapshot,
            json_decode(
                $history->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_submitter_cannot_review_their_own_submitted_version(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->expectValidationFailure(
            'reviewer',
            fn () => $this->service()->reviewReport(
                $school->id,
                $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                $submitter->id
            )
        );

        $this->assertSame(
            'submitted',
            $report->fresh()->status
        );
    }

    public function test_changes_requested_requires_meaningful_comment(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->expectValidationFailure(
            'comment',
            fn () => $this->service()->reviewReport(
                $school->id,
                $report->id,
                'changes_requested',
                '   ',
                $reviewer->id
            )
        );

        $fresh = $report->fresh();

        $this->assertSame(
            'submitted',
            $fresh->status
        );

        $this->assertNull(
            $fresh->reviewed_by
        );

        $this->assertNull(
            $fresh->reviewed_at
        );

        $this->assertNull(
            $fresh->review_comment
        );
    }

    public function test_changes_requested_report_can_be_resubmitted_with_fresh_snapshot(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $firstSubmission = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $firstSnapshot = $firstSubmission->evidence_snapshot;

        $this->assertSame(
            0,
            $firstSnapshot['total_occurrence_count']
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'changes_requested',
            'Please address the additional occurrence.',
            $reviewer->id
        );

        $categoryId = $this->occurrenceCategory(
            $school,
            $submitter,
            'pastoral_follow_up',
            'Pastoral Follow Up'
        );

        $this->occurrence(
            $school,
            $periodId,
            $categoryId,
            $submitter,
            '2026-09-10',
            'Pastoral follow-up occurrence.'
        );

        $resubmitted = $this->service()->resubmitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $secondSnapshot = $resubmitted->evidence_snapshot;

        $this->assertSame(
            $report->id,
            $resubmitted->id
        );

        $this->assertSame(
            'submitted',
            $resubmitted->status
        );

        $this->assertSame(
            $submitter->id,
            $resubmitted->submitted_by
        );

        $this->assertNotNull(
            $resubmitted->submitted_at
        );

        $this->assertNull(
            $resubmitted->reviewed_by
        );

        $this->assertNull(
            $resubmitted->reviewed_at
        );

        $this->assertNull(
            $resubmitted->review_comment
        );

        $this->assertSame(
            1,
            $secondSnapshot['total_occurrence_count']
        );

        $this->assertNotSame(
            $firstSnapshot,
            $secondSnapshot
        );

        $changesHistory = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'changes_requested')
            ->first();

        $this->assertNotNull($changesHistory);

        $this->assertSame(
            $firstSnapshot,
            json_decode(
                $changesHistory->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $resubmitHistory = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'resubmitted')
            ->first();

        $this->assertNotNull($resubmitHistory);

        $this->assertSame(
            'changes_requested',
            $resubmitHistory->from_status
        );

        $this->assertSame(
            'submitted',
            $resubmitHistory->to_status
        );

        $this->assertSame(
            $secondSnapshot,
            json_decode(
                $resubmitHistory->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_reviewer_can_approve_submitted_report(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $approved = $this->service()->reviewReport(
            $school->id,
            $report->id,
            'approved',
            null,
            $reviewer->id
        );

        $this->assertSame('approved', $approved->status);
        $this->assertSame($reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertNull($approved->review_comment);
        $this->assertSame($snapshot, $approved->evidence_snapshot);

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'approved')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('submitted', $history->from_status);
        $this->assertSame('approved', $history->to_status);
        $this->assertSame($reviewer->id, $history->actor_user_id);
        $this->assertNull($history->comment);

        $this->assertSame(
            $snapshot,
            json_decode(
                $history->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_reviewer_can_reject_submitted_report_with_meaningful_comment(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $rejected = $this->service()->reviewReport(
            $school->id,
            $report->id,
            'rejected',
            'The weekly report requires substantial correction.',
            $reviewer->id
        );

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($reviewer->id, $rejected->reviewed_by);
        $this->assertNotNull($rejected->reviewed_at);

        $this->assertSame(
            'The weekly report requires substantial correction.',
            $rejected->review_comment
        );

        $this->assertSame($snapshot, $rejected->evidence_snapshot);

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'rejected')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('submitted', $history->from_status);
        $this->assertSame('rejected', $history->to_status);
        $this->assertSame($reviewer->id, $history->actor_user_id);

        $this->assertSame(
            'The weekly report requires substantial correction.',
            $history->comment
        );

        $this->assertSame(
            $snapshot,
            json_decode(
                $history->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_approved_report_is_terminal(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $secondReviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'approved',
            null,
            $reviewer->id
        );

        $this->expectValidationFailure(
            'report_id',
            fn () => $this->service()->reviewReport(
                $school->id,
                $report->id,
                'rejected',
                'Second review must not be allowed.',
                $secondReviewer->id
            )
        );
    }

    public function test_rejected_report_is_terminal_and_cannot_be_resubmitted(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'rejected',
            'The report is rejected.',
            $reviewer->id
        );

        $this->expectValidationFailure(
            'report_id',
            fn () => $this->service()->resubmitReport(
                $school->id,
                $report->id,
                $submitter->id
            )
        );
    }

    public function test_state_returns_current_lifecycle_and_latest_submission_evidence_without_mutation(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $reportBefore = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyBefore = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $state = $this->service()->state(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame(
            'SUBMITTED',
            $state['state']
        );

        $this->assertSame(
            $snapshot,
            $state['latest_submission_evidence_snapshot']
        );

        $reportAfter = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyAfter = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $this->assertSame(
            $reportBefore,
            $reportAfter
        );

        $this->assertSame(
            $historyBefore,
            $historyAfter
        );
    }

    public function test_state_returns_current_authoritative_daily_completeness_without_rewriting_submission_snapshot(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $frozenSnapshot = $submitted->evidence_snapshot;

        $this->assertSame(
            0,
            $frozenSnapshot['submitted_daily_report_count']
        );

        $this->dailyReport(
            $school,
            $periodId,
            $actor,
            '2026-09-09',
            'submitted',
            '2026-09-09 19:00:00+03',
            '2026-09-09 18:30:00+03'
        );

        $state = $this->service()->state(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame(
            5,
            $state['current_daily_completeness'][
                'expected_daily_report_count'
            ]
        );

        $this->assertSame(
            1,
            $state['current_daily_completeness'][
                'submitted_daily_report_count'
            ]
        );

        $this->assertSame(
            0,
            $state['current_daily_completeness'][
                'late_submitted_daily_report_count'
            ]
        );

        $this->assertSame(
            0,
            $state['latest_submission_evidence_snapshot'][
                'submitted_daily_report_count'
            ]
        );

        $persisted = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereKey($report->id)
            ->firstOrFail();

        $this->assertSame(
            $frozenSnapshot,
            $persisted->evidence_snapshot
        );
    }

    public function test_state_returns_current_authoritative_occurrence_aggregates_without_rewriting_submission_snapshot(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $pastoralCategoryId = $this->occurrenceCategory(
            $school,
            $actor,
            'pastoral_support',
            'Pastoral Support'
        );

        $medicalSupportCategoryId = $this->occurrenceCategory(
            $school,
            $actor,
            'medical_support',
            'Medical Support'
        );

        $this->occurrence(
            $school,
            $periodId,
            $pastoralCategoryId,
            $actor,
            '2026-09-08',
            'Initial pastoral occurrence.'
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $frozenSnapshot = $submitted->evidence_snapshot;

        $this->assertSame(
            1,
            $frozenSnapshot['total_occurrence_count']
        );

        $this->occurrence(
            $school,
            $periodId,
            $medicalSupportCategoryId,
            $actor,
            '2026-09-09',
            'Later medical occurrence.'
        );

        $state = $this->service()->state(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame(
            2,
            $state['current_occurrence_aggregates'][
                'total_occurrence_count'
            ]
        );

        $this->assertSame(
            [
                [
                    'category_id' => $medicalSupportCategoryId,
                    'category_code' => 'medical_support',
                    'category_name' => 'Medical Support',
                    'occurrence_count' => 1,
                ],
                [
                    'category_id' => $pastoralCategoryId,
                    'category_code' => 'pastoral_support',
                    'category_name' => 'Pastoral Support',
                    'occurrence_count' => 1,
                ],
            ],
            $state['current_occurrence_aggregates'][
                'occurrence_category_breakdown'
            ]
        );

        $this->assertSame(
            1,
            $state['latest_submission_evidence_snapshot'][
                'total_occurrence_count'
            ]
        );

        $persisted = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereKey($report->id)
            ->firstOrFail();

        $this->assertSame(
            $frozenSnapshot,
            $persisted->evidence_snapshot
        );
    }

    public function test_state_fails_closed_when_report_belongs_to_another_school(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $otherSchool = $this->school();
        $this->settings($otherSchool);

        $otherActor = $this->user($otherSchool);

        try {
            $this->service()->state(
                $otherSchool->id,
                $report->id,
                $otherActor->id
            );

            $this->fail(
                'Expected cross-tenant weekly report read to fail closed.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'report_id',
                $exception->errors()
            );
        }
    }

    public function test_state_fails_closed_when_actor_belongs_to_another_school(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $otherSchool = $this->school();
        $otherActor = $this->user($otherSchool);

        try {
            $this->service()->state(
                $school->id,
                $report->id,
                $otherActor->id
            );

            $this->fail(
                'Expected cross-tenant weekly report actor to fail closed.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'actor_user_id',
                $exception->errors()
            );
        }
    }

    public function test_state_returns_submission_review_evidence_and_immutable_history(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $approved = $this->service()->reviewReport(
            $school->id,
            $report->id,
            'approved',
            null,
            $reviewer->id
        );

        $reportBefore = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyBefore = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $school->id)
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $state = $this->service()->state(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->assertSame(
            $submitter->id,
            $state['submission_evidence']['submitted_by']
        );

        $this->assertSame(
            $approved->submitted_at?->toISOString(),
            $state['submission_evidence']['submitted_at']
        );

        $this->assertSame(
            $reviewer->id,
            $state['review_evidence']['reviewed_by']
        );

        $this->assertSame(
            $approved->reviewed_at?->toISOString(),
            $state['review_evidence']['reviewed_at']
        );

        $this->assertNull(
            $state['review_evidence']['review_comment']
        );

        $this->assertSame(
            $snapshot,
            $state['latest_submission_evidence_snapshot']
        );

        $this->assertCount(
            3,
            $state['history']
        );

        $this->assertSame(
            ['created', 'submitted', 'approved'],
            array_column(
                $state['history'],
                'event'
            )
        );

        $this->assertSame(
            $snapshot,
            $state['history'][1]['evidence_snapshot']
        );

        $this->assertSame(
            $snapshot,
            $state['history'][2]['evidence_snapshot']
        );

        $reportAfter = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyAfter = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $school->id)
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $this->assertSame(
            $reportBefore,
            $reportAfter
        );

        $this->assertSame(
            $historyBefore,
            $historyAfter
        );
    }

    public function test_state_returns_current_narrative_without_mutation(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        $this->service()->updateReport(
            $school->id,
            $report->id,
            'Weekly summary',
            'Strong punctuality',
            'Two discipline cases',
            'Increase gate supervision',
            $actor->id
        );

        $reportBefore = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyBefore = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $school->id)
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $state = $this->service()->state(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->assertSame(
            [
                'summary' => 'Weekly summary',
                'highlights' => 'Strong punctuality',
                'challenges' => 'Two discipline cases',
                'recommendations' => 'Increase gate supervision',
            ],
            $state['current_narrative']
        );

        $reportAfter = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyAfter = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $school->id)
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $this->assertSame(
            $reportBefore,
            $reportAfter
        );

        $this->assertSame(
            $historyBefore,
            $historyAfter
        );
    }

    private function service(): TeacherDutyWeeklyReportService
    {
        return app(TeacherDutyWeeklyReportService::class);
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

    private function occurrenceCategory(
        School $school,
        User $actor,
        string $code,
        string $name
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_occurrence_categories')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'code' => $code,
            'name' => $name,
            'description' => null,
            'display_order' => 10,
            'is_canonical' => false,
            'created_by' => $actor->id,
            'active' => true,
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
        string $occurrenceDate,
        string $description
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_occurrences')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'occurrence_category_id' => $categoryId,
            'occurrence_date' => $occurrenceDate,
            'occurrence_time' => '10:00:00',
            'description' => $description,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function dailyReport(
        School $school,
        string $periodId,
        User $actor,
        string $reportDate,
        string $status,
        string $deadlineAt,
        ?string $submittedAt
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_daily_reports')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'report_date' => $reportDate,
            'status' => $status,
            'summary' => null,
            'deadline_at' => $deadlineAt,
            'created_by' => $actor->id,
            'submitted_by' => $status === 'submitted'
                ? $actor->id
                : null,
            'submitted_at' => $submittedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
            'school_name' => 'Weekly Reporting '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'WRS-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'WRS',
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
            'role_name' => 'Weekly Reporting '.Str::upper(
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
            'username' => 'weekly_reporting_'.Str::lower(
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

    public function test_weekly_reporting_concurrency_contract_uses_transactions_locks_and_database_backstop(): void
    {
        $service = file_get_contents(
            app_path(
                'Services/TeacherDuty/TeacherDutyWeeklyReportService.php'
            )
        );

        $migration = file_get_contents(
            database_path(
                'migrations/2026_09_12_071700_create_teacher_duty_weekly_reporting_domain.php'
            )
        );

        $this->assertIsString($service);
        $this->assertIsString($migration);

        $this->assertGreaterThanOrEqual(
            5,
            substr_count(
                $service,
                'DB::transaction('
            )
        );

        $this->assertGreaterThanOrEqual(
            5,
            substr_count(
                $service,
                '->lockForUpdate()'
            )
        );

        $this->assertStringContainsString(
            'private function lockEligibleUser(',
            $service
        );

        $this->assertStringContainsString(
            'private function lockPeriod(',
            $service
        );

        $this->assertStringContainsString(
            'private function lockReport(',
            $service
        );

        $this->assertStringContainsString(
            'private function lockSettings(',
            $service
        );

        $openPeriodLock = strpos(
            $service,
            '$period = $this->lockPeriod('
        );

        $openExistingLookup = strpos(
            $service,
            '$existing ='
        );

        $this->assertNotFalse($openPeriodLock);
        $this->assertNotFalse($openExistingLookup);

        $this->assertLessThan(
            $openExistingLookup,
            $openPeriodLock
        );

        $this->assertStringContainsString(
            'td_weekly_reports_school_period_unique',
            $migration
        );

        $this->assertStringContainsString(
            'school_id,',
            $migration
        );

        $this->assertStringContainsString(
            'duty_period_id',
            $migration
        );
    }

    public function test_submitted_report_cannot_be_edited(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $actor->id
        );

        $this->expectValidationFailure(
            'report_id',
            fn () => $this->service()->updateReport(
                $school->id,
                $report->id,
                'Edited summary',
                'Edited highlights',
                'Edited challenges',
                'Edited recommendations',
                $actor->id
            )
        );
    }

    public function test_changes_requested_report_can_be_edited(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'changes_requested',
            'Please revise the narrative.',
            $reviewer->id
        );

        $updated = $this->service()->updateReport(
            $school->id,
            $report->id,
            'Revised summary',
            'Revised highlights',
            'Revised challenges',
            'Revised recommendations',
            $submitter->id
        );

        $this->assertSame(
            'changes_requested',
            $updated->status
        );

        $this->assertSame(
            'Revised summary',
            $updated->summary
        );

        $this->assertSame(
            'Revised highlights',
            $updated->highlights
        );

        $this->assertSame(
            'Revised challenges',
            $updated->challenges
        );

        $this->assertSame(
            'Revised recommendations',
            $updated->recommendations
        );
    }

    public function test_approved_report_cannot_be_edited(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'approved',
            null,
            $reviewer->id
        );

        $this->expectValidationFailure(
            'report_id',
            fn () => $this->service()->updateReport(
                $school->id,
                $report->id,
                'Edited summary',
                'Edited highlights',
                'Edited challenges',
                'Edited recommendations',
                $submitter->id
            )
        );
    }

    public function test_rejected_report_cannot_be_edited(): void
    {
        $school = $this->school();
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
            ]);

        $this->service()->submitReport(
            $school->id,
            $report->id,
            $submitter->id
        );

        $this->service()->reviewReport(
            $school->id,
            $report->id,
            'rejected',
            'The report requires substantial correction.',
            $reviewer->id
        );

        $this->expectValidationFailure(
            'report_id',
            fn () => $this->service()->updateReport(
                $school->id,
                $report->id,
                'Edited summary',
                'Edited highlights',
                'Edited challenges',
                'Edited recommendations',
                $submitter->id
            )
        );
    }

    public function test_submission_rolls_back_report_when_history_write_fails(): void
    {
        $school = $this->school();
        $this->settings($school);

        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $actor->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
            ]);

        $reportBefore = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyBefore = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $failHistorySave = true;

        TeacherDutyWeeklyReportHistory::saving(
            function () use (&$failHistorySave): void {
                if ($failHistorySave) {
                    throw new \RuntimeException(
                        'Forced weekly-report history failure.'
                    );
                }
            }
        );

        $exceptionThrown = false;

        try {
            $this->service()->submitReport(
                $school->id,
                $report->id,
                $actor->id
            );
        } catch (\RuntimeException $exception) {
            $exceptionThrown = true;

            $this->assertSame(
                'Forced weekly-report history failure.',
                $exception->getMessage()
            );
        } finally {
            /*
             * Leave the registered listener inert so it cannot affect
             * later tests in the same PHPUnit process.
             */
            $failHistorySave = false;
        }

        $this->assertTrue($exceptionThrown);

        $fresh = $report->fresh();

        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->submitted_by);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->evidence_snapshot);

        $reportAfter = (array) DB::table(
            'teacher_duty_weekly_reports'
        )
            ->where('id', $report->id)
            ->first();

        $historyAfter = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn ($row): array => (array) $row
            )
            ->all();

        $this->assertSame(
            $reportBefore,
            $reportAfter
        );

        $this->assertSame(
            $historyBefore,
            $historyAfter
        );

        $this->assertDatabaseMissing(
            'teacher_duty_weekly_report_history',
            [
                'weekly_report_id' => $report->id,
                'event' => 'submitted',
            ]
        );
    }

    public function test_timezone_sensitive_lifecycle_timestamps_preserve_the_correct_instant(): void
    {
        $school = $this->school('Pacific/Auckland');
        $this->settings($school);

        $submitter = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $submitter);

        $report = $this->service()->openReport(
            $school->id,
            $periodId,
            $submitter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $submitter->id,
                'ended_at' => now(),
            ]);

        $submissionInstant = CarbonImmutable::parse(
            '2026-09-12T10:15:30+00:00'
        );

        CarbonImmutable::setTestNow(
            $submissionInstant
        );

        try {
            $submitted = $this->service()->submitReport(
                $school->id,
                $report->id,
                $submitter->id
            );

            $this->assertSame(
                $submissionInstant->getTimestamp(),
                $submitted->submitted_at->getTimestamp()
            );

            $this->assertSame(
                $submissionInstant->getTimestamp(),
                CarbonImmutable::parse(
                    $submitted->evidence_snapshot[
                        'snapshot_generated_at'
                    ]
                )->getTimestamp()
            );

            $state = $this->service()->state(
                $school->id,
                $report->id,
                $submitter->id
            );

            $this->assertSame(
                $submissionInstant->getTimestamp(),
                CarbonImmutable::parse(
                    $state[
                        'submission_evidence'
                    ]['submitted_at']
                )->getTimestamp()
            );

            $reviewInstant = CarbonImmutable::parse(
                '2026-09-12T12:45:00+00:00'
            );

            CarbonImmutable::setTestNow(
                $reviewInstant
            );

            $approved = $this->service()->reviewReport(
                $school->id,
                $report->id,
                'approved',
                null,
                $reviewer->id
            );

            $this->assertSame(
                $reviewInstant->getTimestamp(),
                $approved->reviewed_at->getTimestamp()
            );

            $reviewedState = $this->service()->state(
                $school->id,
                $report->id,
                $reviewer->id
            );

            $this->assertSame(
                $reviewInstant->getTimestamp(),
                CarbonImmutable::parse(
                    $reviewedState[
                        'review_evidence'
                    ]['reviewed_at']
                )->getTimestamp()
            );

            $this->assertSame(
                $submissionInstant->getTimestamp(),
                CarbonImmutable::parse(
                    $reviewedState[
                        'latest_submission_evidence_snapshot'
                    ]['snapshot_generated_at']
                )->getTimestamp()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_reporting_fails_closed_when_tenant_context_is_missing(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->service()->openReport(
            '',
            $periodId,
            $actor->id
        );
    }

    public function test_public_mutation_api_does_not_accept_server_owned_weekly_report_fields(): void
    {
        $allowedParameters = [
            'openReport' => [
                'schoolId',
                'periodId',
                'actorUserId',
            ],
            'updateReport' => [
                'schoolId',
                'reportId',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'actorUserId',
            ],
            'submitReport' => [
                'schoolId',
                'reportId',
                'actorUserId',
            ],
            'resubmitReport' => [
                'schoolId',
                'reportId',
                'actorUserId',
            ],
            'reviewReport' => [
                'schoolId',
                'reportId',
                'decision',
                'comment',
                'actorUserId',
            ],
        ];

        foreach ($allowedParameters as $method => $expected) {
            $reflection = new \ReflectionMethod(
                TeacherDutyWeeklyReportService::class,
                $method
            );

            $actual = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $reflection->getParameters()
            );

            $this->assertSame(
                $expected,
                $actual,
                sprintf(
                    '%s exposes an unexpected client-controlled parameter.',
                    $method
                )
            );
        }
    }
}
