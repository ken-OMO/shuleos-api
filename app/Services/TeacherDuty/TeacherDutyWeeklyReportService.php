<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use App\Models\SchoolSettings;
use App\Models\TeacherDutyPeriod;
use App\Models\TeacherDutyWeeklyReport;
use App\Models\TeacherDutyWeeklyReportHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class TeacherDutyWeeklyReportService
{
    public function openReport(
        string $schoolId,
        string $periodId,
        string $actorUserId
    ): TeacherDutyWeeklyReport {
        return DB::transaction(function () use (
            $schoolId,
            $periodId,
            $actorUserId
        ): TeacherDutyWeeklyReport {
            $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            /*
             * Locking the authoritative duty-period row serializes concurrent
             * open attempts for the same weekly-report identity.
             */
            $period = $this->lockPeriod(
                $schoolId,
                $periodId
            );

            $existing = TeacherDutyWeeklyReport::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('duty_period_id', $period->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $report = new TeacherDutyWeeklyReport;

            $report->school_id = $schoolId;
            $report->duty_period_id = $period->id;
            $report->status = 'draft';

            $report->summary = null;
            $report->highlights = null;
            $report->challenges = null;
            $report->recommendations = null;

            $report->evidence_snapshot = null;

            $report->created_by = $actor->id;

            $report->submitted_by = null;
            $report->submitted_at = null;

            $report->reviewed_by = null;
            $report->reviewed_at = null;
            $report->review_comment = null;

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $actor->id,
                null,
                'draft',
                'created',
                null,
                null
            );

            return $report->refresh();
        }, 3);
    }

    public function updateReport(
        string $schoolId,
        string $reportId,
        ?string $summary,
        ?string $highlights,
        ?string $challenges,
        ?string $recommendations,
        string $actorUserId
    ): TeacherDutyWeeklyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $summary,
            $highlights,
            $challenges,
            $recommendations,
            $actorUserId
        ): TeacherDutyWeeklyReport {
            $this->school($schoolId);

            $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if (
                $report->status !== 'draft'
                && $report->status !== 'changes_requested'
            ) {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a draft or changes-requested teacher duty weekly report may be updated.',
                    ],
                ]);
            }

            $report->summary = $this->normalizeNarrative($summary);
            $report->highlights = $this->normalizeNarrative($highlights);
            $report->challenges = $this->normalizeNarrative($challenges);
            $report->recommendations = $this->normalizeNarrative(
                $recommendations
            );

            $report->save();

            return $report->refresh();
        }, 3);
    }

    public function submitReport(
        string $schoolId,
        string $reportId,
        string $actorUserId
    ): TeacherDutyWeeklyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $actorUserId
        ): TeacherDutyWeeklyReport {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if ($report->status !== 'draft') {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a draft teacher duty weekly report may be submitted.',
                    ],
                ]);
            }

            $period = $this->lockPeriod(
                $schoolId,
                $report->duty_period_id
            );

            if (
                $period->active
                || $period->ended_by === null
                || $period->ended_at === null
            ) {
                throw ValidationException::withMessages([
                    'period_id' => [
                        'The teacher duty period must be authoritatively ended before weekly submission.',
                    ],
                ]);
            }

            $settings = $this->lockSettings($schoolId);

            $submittedAt = CarbonImmutable::now(
                $this->timezone($school)
            );

            $snapshot = $this->buildEvidenceSnapshot(
                $school,
                $settings,
                $period,
                $submittedAt
            );

            $report->status = 'submitted';
            $report->submitted_by = $actor->id;
            $report->submitted_at = $submittedAt;
            $report->evidence_snapshot = $snapshot;

            $report->reviewed_by = null;
            $report->reviewed_at = null;
            $report->review_comment = null;

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $actor->id,
                'draft',
                'submitted',
                'submitted',
                null,
                $snapshot
            );

            return $report->refresh();
        }, 3);
    }

    private function school(string $schoolId): School
    {
        if (! Str::isUuid($schoolId)) {
            throw (new ModelNotFoundException)
                ->setModel(School::class, [$schoolId]);
        }

        return School::query()
            ->withoutGlobalScopes()
            ->whereKey($schoolId)
            ->firstOrFail();
    }

    private function lockEligibleUser(
        string $schoolId,
        string $userId,
        string $field
    ): User {
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('id', $userId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->whereNull('suspended_at')
            ->lockForUpdate()
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                $field => [
                    'The selected school user is not eligible for teacher duty weekly reporting.',
                ],
            ]);
        }

        return $user;
    }

    private function lockPeriod(
        string $schoolId,
        string $periodId
    ): TeacherDutyPeriod {
        $period = TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($periodId)
            ->lockForUpdate()
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => [
                    'The selected teacher duty period does not belong to this school.',
                ],
            ]);
        }

        return $period;
    }

    public function resubmitReport(
        string $schoolId,
        string $reportId,
        string $actorUserId
    ): TeacherDutyWeeklyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $actorUserId
        ): TeacherDutyWeeklyReport {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if ($report->status !== 'changes_requested') {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a teacher duty weekly report with changes requested may be resubmitted.',
                    ],
                ]);
            }

            $period = $this->lockPeriod(
                $schoolId,
                $report->duty_period_id
            );

            if (
                $period->active
                || $period->ended_by === null
                || $period->ended_at === null
            ) {
                throw ValidationException::withMessages([
                    'period_id' => [
                        'The teacher duty period must be authoritatively ended before weekly resubmission.',
                    ],
                ]);
            }

            $settings = $this->lockSettings($schoolId);

            $submittedAt = CarbonImmutable::now(
                $this->timezone($school)
            );

            $snapshot = $this->buildEvidenceSnapshot(
                $school,
                $settings,
                $period,
                $submittedAt
            );

            $report->forceFill([
                'status' => 'submitted',
                'submitted_by' => $actor->id,
                'submitted_at' => $submittedAt,
                'evidence_snapshot' => $snapshot,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ]);

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $actor->id,
                'changes_requested',
                'submitted',
                'resubmitted',
                null,
                $snapshot
            );

            return $report->refresh();
        }, 3);
    }

    public function reviewReport(
        string $schoolId,
        string $reportId,
        string $decision,
        ?string $comment,
        string $actorUserId
    ): TeacherDutyWeeklyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $decision,
            $comment,
            $actorUserId
        ): TeacherDutyWeeklyReport {
            $school = $this->school($schoolId);

            $reviewer = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'reviewer'
            );

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if ($report->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a submitted teacher duty weekly report may be reviewed.',
                    ],
                ]);
            }

            if (! in_array(
                $decision,
                ['changes_requested', 'approved', 'rejected'],
                true
            )) {
                throw ValidationException::withMessages([
                    'decision' => [
                        'The selected teacher duty weekly review decision is invalid.',
                    ],
                ]);
            }

            if ($report->submitted_by === $reviewer->id) {
                throw ValidationException::withMessages([
                    'reviewer' => [
                        'The reviewer cannot be the submitter of the current weekly report version.',
                    ],
                ]);
            }

            $normalizedComment = $this->normalizeNarrative($comment);

            if (
                in_array(
                    $decision,
                    ['changes_requested', 'rejected'],
                    true
                )
                && $normalizedComment === null
            ) {
                throw ValidationException::withMessages([
                    'comment' => [
                        'A meaningful review comment is required for this review decision.',
                    ],
                ]);
            }

            $reviewedAt = CarbonImmutable::now(
                $this->timezone($school)
            );

            $report->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
                'review_comment' => $normalizedComment,
            ]);

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $reviewer->id,
                'submitted',
                $decision,
                $decision,
                $normalizedComment,
                $report->evidence_snapshot
            );

            return $report->refresh();
        }, 3);
    }

    public function state(
        string $schoolId,
        string $reportId,
        string $actorUserId
    ): array {
        $school = $this->school($schoolId);

        $actor = User::query()
            ->withoutGlobalScopes()
            ->where('id', $actorUserId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->whereNull('suspended_at')
            ->first();

        if (! $actor) {
            throw ValidationException::withMessages([
                'actor_user_id' => [
                    'The selected school user is not eligible for teacher duty weekly reporting.',
                ],
            ]);
        }

        $report = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($reportId)
            ->first();

        if (! $report) {
            throw ValidationException::withMessages([
                'report_id' => [
                    'The selected teacher duty weekly report does not belong to this school.',
                ],
            ]);
        }

        $period = TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($report->duty_period_id)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => [
                    'The selected teacher duty period does not belong to this school.',
                ],
            ]);
        }

        $settings = SchoolSettings::query()
            ->where('school_id', $schoolId)
            ->first();

        if (! $settings) {
            throw ValidationException::withMessages([
                'school_settings' => [
                    'Persistent school settings are required for teacher duty weekly reporting.',
                ],
            ]);
        }

        $currentEvidence = $this->buildEvidenceSnapshot(
            $school,
            $settings,
            $period,
            CarbonImmutable::now(
                $this->timezone($school)
            )
        );

        return [
            'state' => strtoupper($report->status),
            'current_narrative' => [
                'summary' => $report->summary,
                'highlights' => $report->highlights,
                'challenges' => $report->challenges,
                'recommendations' => $report->recommendations,
            ],
            'current_daily_completeness' => [
                'expected_daily_report_count' => $currentEvidence['expected_daily_report_count'],
                'not_started_daily_report_count' => $currentEvidence['not_started_daily_report_count'],
                'draft_daily_report_count' => $currentEvidence['draft_daily_report_count'],
                'overdue_daily_report_count' => $currentEvidence['overdue_daily_report_count'],
                'submitted_daily_report_count' => $currentEvidence['submitted_daily_report_count'],
                'late_submitted_daily_report_count' => $currentEvidence['late_submitted_daily_report_count'],
            ],
            'current_occurrence_aggregates' => [
                'total_occurrence_count' => $currentEvidence['total_occurrence_count'],
                'occurrence_category_breakdown' => $currentEvidence['occurrence_category_breakdown'],
            ],
            'submission_evidence' => [
                'submitted_by' => $report->submitted_by,
                'submitted_at' => $report->submitted_at?->toISOString(),
            ],
            'review_evidence' => [
                'reviewed_by' => $report->reviewed_by,
                'reviewed_at' => $report->reviewed_at?->toISOString(),
                'review_comment' => $report->review_comment,
            ],
            'latest_submission_evidence_snapshot' => $report->evidence_snapshot,
            'history' => DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $schoolId)
                ->where('weekly_report_id', $report->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($row): array => [
                        'id' => (string) $row->id,
                        'event' => (string) $row->event,
                        'from_status' => $row->from_status,
                        'to_status' => (string) $row->to_status,
                        'actor_user_id' => (string) $row->actor_user_id,
                        'comment' => $row->comment,
                        'evidence_snapshot' => $row->evidence_snapshot === null
                                ? null
                                : json_decode(
                                    $row->evidence_snapshot,
                                    true,
                                    512,
                                    JSON_THROW_ON_ERROR
                                ),
                        'created_at' => $row->created_at,
                    ]
                )
                ->all(),
        ];
    }

    private function lockReport(
        string $schoolId,
        string $reportId
    ): TeacherDutyWeeklyReport {
        $report = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($reportId)
            ->lockForUpdate()
            ->first();

        if (! $report) {
            throw ValidationException::withMessages([
                'report_id' => [
                    'The selected teacher duty weekly report does not belong to this school.',
                ],
            ]);
        }

        return $report;
    }

    private function normalizeNarrative(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === ''
            ? null
            : $normalized;
    }

    private function lockSettings(
        string $schoolId
    ): SchoolSettings {
        $settings = SchoolSettings::query()
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first();

        if (! $settings) {
            throw ValidationException::withMessages([
                'school_settings' => [
                    'Persistent school settings are required for teacher duty weekly reporting.',
                ],
            ]);
        }

        return $settings;
    }

    private function buildEvidenceSnapshot(
        School $school,
        SchoolSettings $settings,
        TeacherDutyPeriod $period,
        CarbonImmutable $generatedAt
    ): array {
        $timezone = $this->timezone($school);

        $startDate = $period->start_date?->toDateString();
        $endDate = $period->end_date?->toDateString();

        if ($startDate === null || $endDate === null) {
            throw ValidationException::withMessages([
                'period_id' => [
                    'The teacher duty period must have valid start and end dates.',
                ],
            ]);
        }

        $dailyReports = DB::table('teacher_duty_daily_reports')
            ->where('school_id', $school->id)
            ->where('duty_period_id', $period->id)
            ->whereBetween(
                'report_date',
                [
                    $startDate,
                    $endDate,
                ]
            )
            ->get()
            ->keyBy(
                fn ($row): string => (string) $row->report_date
            );

        $notStartedCount = 0;
        $draftCount = 0;
        $overdueCount = 0;
        $submittedCount = 0;
        $lateSubmittedCount = 0;
        $expectedCount = 0;

        $currentDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $startDate,
            $timezone
        );

        $lastDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $endDate,
            $timezone
        );

        if (! $currentDate || ! $lastDate) {
            throw ValidationException::withMessages([
                'period_id' => [
                    'The teacher duty period contains invalid dates.',
                ],
            ]);
        }

        while ($currentDate->lte($lastDate)) {
            $expectedCount++;

            $date = $currentDate->format('Y-m-d');
            $dailyReport = $dailyReports->get($date);

            if (! $dailyReport) {
                $deadline = $this->dailyDeadline(
                    $school,
                    $settings,
                    $date
                );

                if ($generatedAt->gte($deadline)) {
                    $overdueCount++;
                } else {
                    $notStartedCount++;
                }

                $currentDate = $currentDate->addDay();

                continue;
            }

            if ($dailyReport->status === 'submitted') {
                $submittedCount++;

                if (
                    $dailyReport->submitted_at !== null
                    && CarbonImmutable::parse(
                        $dailyReport->submitted_at
                    )->gt(
                        CarbonImmutable::parse(
                            $dailyReport->deadline_at
                        )
                    )
                ) {
                    $lateSubmittedCount++;
                }

                $currentDate = $currentDate->addDay();

                continue;
            }

            if ($dailyReport->status === 'draft') {
                $deadline = CarbonImmutable::parse(
                    $dailyReport->deadline_at
                );

                if ($generatedAt->gte($deadline)) {
                    $overdueCount++;
                } else {
                    $draftCount++;
                }

                $currentDate = $currentDate->addDay();

                continue;
            }

            throw new LogicException(
                'Unexpected teacher duty daily report status.'
            );
        }

        $occurrenceRows = DB::table(
            'teacher_duty_occurrences as occurrences'
        )
            ->join(
                'teacher_duty_occurrence_categories as categories',
                function ($join): void {
                    $join->on(
                        'categories.id',
                        '=',
                        'occurrences.occurrence_category_id'
                    );

                    $join->on(
                        'categories.school_id',
                        '=',
                        'occurrences.school_id'
                    );
                }
            )
            ->where(
                'occurrences.school_id',
                $school->id
            )
            ->where(
                'occurrences.duty_period_id',
                $period->id
            )
            ->whereBetween(
                'occurrences.occurrence_date',
                [
                    $startDate,
                    $endDate,
                ]
            )
            ->groupBy(
                'categories.id',
                'categories.code',
                'categories.name'
            )
            ->orderBy('categories.code')
            ->select([
                'categories.id as category_id',
                'categories.code as category_code',
                'categories.name as category_name',
                DB::raw('COUNT(*) as occurrence_count'),
            ])
            ->get();

        $categoryBreakdown = $occurrenceRows
            ->map(
                fn ($row): array => [
                    'category_id' => (string) $row->category_id,
                    'category_code' => (string) $row->category_code,
                    'category_name' => (string) $row->category_name,
                    'occurrence_count' => (int) $row->occurrence_count,
                ]
            )
            ->values()
            ->all();

        $totalOccurrenceCount = array_sum(
            array_column(
                $categoryBreakdown,
                'occurrence_count'
            )
        );

        return [
            'snapshot_version' => 1,
            'duty_period_start_date' => $startDate,
            'duty_period_end_date' => $endDate,
            'expected_daily_report_count' => $expectedCount,
            'not_started_daily_report_count' => $notStartedCount,
            'draft_daily_report_count' => $draftCount,
            'overdue_daily_report_count' => $overdueCount,
            'submitted_daily_report_count' => $submittedCount,
            'late_submitted_daily_report_count' => $lateSubmittedCount,
            'total_occurrence_count' => $totalOccurrenceCount,
            'occurrence_category_breakdown' => $categoryBreakdown,
            'snapshot_generated_at' => $generatedAt->toISOString(),
        ];
    }

    private function dailyDeadline(
        School $school,
        SchoolSettings $settings,
        string $reportDate
    ): CarbonImmutable {
        $deadlineTime = trim(
            (string) $settings->teacher_duty_report_deadline_time
        );

        $graceMinutes =
            $settings->teacher_duty_report_grace_minutes;

        if (
            $deadlineTime === ''
            || $graceMinutes === null
            || $graceMinutes < 0
        ) {
            throw ValidationException::withMessages([
                'school_settings' => [
                    'Teacher duty daily reporting settings are invalid.',
                ],
            ]);
        }

        try {
            $deadline = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $reportDate.' '.$deadlineTime,
                $this->timezone($school)
            );
        } catch (Throwable) {
            $deadline = false;
        }

        if (
            ! $deadline
            || $deadline->format('Y-m-d H:i:s')
                !== $reportDate.' '.$deadlineTime
        ) {
            throw ValidationException::withMessages([
                'school_settings' => [
                    'Teacher duty daily reporting deadline time is invalid.',
                ],
            ]);
        }

        return $deadline->addMinutes(
            (int) $graceMinutes
        );
    }

    private function timezone(School $school): string
    {
        return $school->timezone
            ?: (string) config('app.timezone');
    }

    private function appendHistory(
        string $schoolId,
        string $reportId,
        string $actorUserId,
        ?string $fromStatus,
        string $toStatus,
        string $event,
        ?string $comment,
        ?array $evidenceSnapshot
    ): void {
        $history = new TeacherDutyWeeklyReportHistory;

        $history->forceFill([
            'school_id' => $schoolId,
            'weekly_report_id' => $reportId,
            'actor_user_id' => $actorUserId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event' => $event,
            'comment' => $comment,
            'evidence_snapshot' => $evidenceSnapshot,
            'created_at' => now(),
        ]);

        $history->save();
    }
}
