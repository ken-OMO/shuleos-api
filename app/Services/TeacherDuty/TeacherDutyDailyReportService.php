<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use App\Models\SchoolSettings;
use App\Models\TeacherDutyDailyReport;
use App\Models\TeacherDutyDailyReportHistory;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherDutyDailyReportService
{
    public function openReport(
        string $schoolId,
        string $periodId,
        string $reportDate,
        string $actorUserId
    ): TeacherDutyDailyReport {
        return DB::transaction(function () use (
            $schoolId,
            $periodId,
            $reportDate,
            $actorUserId
        ): TeacherDutyDailyReport {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $period = $this->lockPeriod(
                $schoolId,
                $periodId
            );

            $normalizedDate = $this->strictDate(
                $school,
                $reportDate,
                'report_date'
            );

            $this->validateDateWithinPeriod(
                $period,
                $normalizedDate
            );

            $settings = $this->lockSettings($schoolId);

            $existing = TeacherDutyDailyReport::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('duty_period_id', $period->id)
                ->whereDate('report_date', $normalizedDate)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $deadline = $this->deadline(
                $school,
                $settings,
                $normalizedDate
            );

            $report = new TeacherDutyDailyReport;

            $report->school_id = $schoolId;
            $report->duty_period_id = $period->id;
            $report->report_date = $normalizedDate;
            $report->status = 'draft';
            $report->summary = null;
            $report->deadline_at = $deadline;
            $report->created_by = $actor->id;
            $report->submitted_by = null;
            $report->submitted_at = null;

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $actor->id,
                null,
                'draft',
                'created'
            );

            return $report->refresh();
        }, 3);
    }

    public function state(
        string $schoolId,
        string $periodId,
        string $reportDate,
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
                'actor' => [
                    'The selected school user is not eligible for teacher duty daily reporting.',
                ],
            ]);
        }

        $period = TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($periodId)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => [
                    'The selected teacher duty period does not belong to this school.',
                ],
            ]);
        }

        $normalizedDate = $this->strictDate(
            $school,
            $reportDate,
            'report_date'
        );

        $this->validateDateWithinPeriod(
            $period,
            $normalizedDate
        );

        $settings = SchoolSettings::query()
            ->where('school_id', $schoolId)
            ->first();

        if (! $settings) {
            throw ValidationException::withMessages([
                'school_settings' => [
                    'Persistent school settings are required for teacher duty daily reporting.',
                ],
            ]);
        }

        $report = TeacherDutyDailyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $period->id)
            ->whereDate('report_date', $normalizedDate)
            ->first();

        if (! $report) {
            $deadline = $this->deadline(
                $school,
                $settings,
                $normalizedDate
            );

            $now = CarbonImmutable::now(
                $this->timezone($school)
            );

            return [
                'state' => $now->greaterThanOrEqualTo($deadline)
                    ? 'OVERDUE'
                    : 'NOT_STARTED',
                'deadline_at' => $deadline,
                'submitted_at' => null,
                'late' => false,
            ];
        }

        $deadline = CarbonImmutable::instance(
            $report->deadline_at
        );

        $submittedAt = $report->submitted_at === null
            ? null
            : CarbonImmutable::instance(
                $report->submitted_at
            );

        if ($report->status === 'submitted') {
            return [
                'state' => 'SUBMITTED',
                'deadline_at' => $deadline,
                'submitted_at' => $submittedAt,
                'late' => $submittedAt !== null
                    && $submittedAt->greaterThan($deadline),
            ];
        }

        $now = CarbonImmutable::now(
            $this->timezone($school)
        );

        return [
            'state' => $now->greaterThanOrEqualTo($deadline)
                ? 'OVERDUE'
                : 'DRAFT',
            'deadline_at' => $deadline,
            'submitted_at' => null,
            'late' => false,
        ];
    }

    public function updateDraft(
        string $schoolId,
        string $reportId,
        ?string $summary,
        string $actorUserId
    ): TeacherDutyDailyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $summary,
            $actorUserId
        ): TeacherDutyDailyReport {
            $this->school($schoolId);

            $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $this->lockSettings($schoolId);

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if ($report->status !== 'draft') {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a draft teacher duty daily report may be updated.',
                    ],
                ]);
            }

            $normalizedSummary = $summary === null
                ? null
                : trim($summary);

            if ($normalizedSummary === '') {
                $normalizedSummary = null;
            }

            $report->summary = $normalizedSummary;
            $report->save();

            return $report->refresh();
        }, 3);
    }

    public function submitReport(
        string $schoolId,
        string $reportId,
        string $actorUserId
    ): TeacherDutyDailyReport {
        return DB::transaction(function () use (
            $schoolId,
            $reportId,
            $actorUserId
        ): TeacherDutyDailyReport {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $this->lockSettings($schoolId);

            $report = $this->lockReport(
                $schoolId,
                $reportId
            );

            if ($report->status !== 'draft') {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Only a draft teacher duty daily report may be submitted.',
                    ],
                ]);
            }

            $report->status = 'submitted';
            $report->submitted_by = $actor->id;
            $report->submitted_at = CarbonImmutable::now(
                $this->timezone($school)
            );

            $report->save();

            $this->appendHistory(
                $schoolId,
                $report->id,
                $actor->id,
                'draft',
                'submitted',
                'submitted'
            );

            return $report->refresh();
        }, 3);
    }

    private function lockReport(
        string $schoolId,
        string $reportId
    ): TeacherDutyDailyReport {
        $report = TeacherDutyDailyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($reportId)
            ->lockForUpdate()
            ->first();

        if (! $report) {
            throw ValidationException::withMessages([
                'report_id' => [
                    'The selected teacher duty daily report does not belong to this school.',
                ],
            ]);
        }

        return $report;
    }

    private function school(string $schoolId): School
    {
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
                    'The selected school user is not eligible for teacher duty daily reporting.',
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
                    'Persistent school settings are required for teacher duty daily reporting.',
                ],
            ]);
        }

        return $settings;
    }

    private function strictDate(
        School $school,
        string $value,
        string $field
    ): string {
        $candidate = trim($value);

        try {
            $parsed = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $candidate,
                $this->timezone($school)
            );
        } catch (Throwable) {
            $parsed = false;
        }

        if (
            ! $parsed
            || $parsed->format('Y-m-d') !== $candidate
        ) {
            throw ValidationException::withMessages([
                $field => [
                    'The '.$field.' must use YYYY-MM-DD format.',
                ],
            ]);
        }

        return $candidate;
    }

    private function validateDateWithinPeriod(
        TeacherDutyPeriod $period,
        string $reportDate
    ): void {
        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        if (
            $periodStart === null
            || $periodEnd === null
            || $reportDate < $periodStart
            || $reportDate > $periodEnd
        ) {
            throw ValidationException::withMessages([
                'report_date' => [
                    'The report date must fall within the teacher duty period.',
                ],
            ]);
        }
    }

    private function deadline(
        School $school,
        SchoolSettings $settings,
        string $reportDate
    ): CarbonImmutable {
        $deadlineTime = trim(
            (string) $settings->teacher_duty_report_deadline_time
        );

        $graceMinutes = $settings
            ->teacher_duty_report_grace_minutes;

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
        string $event
    ): void {
        $history = new TeacherDutyDailyReportHistory;

        $history->forceFill([
            'school_id' => $schoolId,
            'daily_report_id' => $reportId,
            'actor_user_id' => $actorUserId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event' => $event,
            'created_at' => now(),
        ]);

        $history->save();
    }
}
