<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\AcademicWeek;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherDutyRosterService
{
    public function createPeriod(
        string $schoolId,
        string $startDate,
        string $endDate,
        ?string $academicWeekId,
        string $actorUserId
    ): TeacherDutyPeriod {
        return DB::transaction(function () use (
            $schoolId,
            $startDate,
            $endDate,
            $academicWeekId,
            $actorUserId
        ): TeacherDutyPeriod {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $normalizedStart = $this->strictDate(
                $school,
                $startDate,
                'start_date'
            );

            $normalizedEnd = $this->strictDate(
                $school,
                $endDate,
                'end_date'
            );

            if ($normalizedEnd < $normalizedStart) {
                throw ValidationException::withMessages([
                    'end_date' => [
                        'The end date must be on or after the start date.',
                    ],
                ]);
            }

            $academicWeek = null;

            if (
                $academicWeekId !== null
                && trim($academicWeekId) !== ''
            ) {
                $academicWeek = $this->lockAcademicWeek(
                    $schoolId,
                    trim($academicWeekId)
                );
            }

            $period = new TeacherDutyPeriod;

            $period->school_id = $schoolId;
            $period->academic_week_id = $academicWeek?->id;
            $period->start_date = $normalizedStart;
            $period->end_date = $normalizedEnd;
            $period->active = true;
            $period->created_by = $actor->id;
            $period->ended_by = null;
            $period->ended_at = null;
            $period->end_reason = null;

            $period->save();

            return $period->refresh();
        }, 3);
    }

    public function endPeriod(
        string $schoolId,
        string $periodId,
        string $actorUserId,
        ?string $reason = null
    ): TeacherDutyPeriod {
        $normalizedReason = $this->normalizeReason($reason);

        return DB::transaction(function () use (
            $schoolId,
            $periodId,
            $actorUserId,
            $normalizedReason
        ): TeacherDutyPeriod {
            $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $period = $this->period(
                $schoolId,
                $periodId,
                true
            );

            if (! $period->active) {
                throw ValidationException::withMessages([
                    'period' => [
                        'The teacher duty period has already ended.',
                    ],
                ]);
            }

            $endedAt = now();

            $assignments = TeacherDutyAssignment::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('duty_period_id', $period->id)
                ->where('active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->active = false;
                $assignment->ended_by = $actor->id;
                $assignment->ended_at = $endedAt;
                $assignment->end_reason = $normalizedReason;
                $assignment->save();
            }

            $period->active = false;
            $period->ended_by = $actor->id;
            $period->ended_at = $endedAt;
            $period->end_reason = $normalizedReason;
            $period->save();

            return $period->refresh();
        }, 3);
    }

    public function assignTeacher(
        string $schoolId,
        string $periodId,
        string $teacherId,
        string $actorUserId
    ): TeacherDutyAssignment {
        try {
            return DB::transaction(function () use (
                $schoolId,
                $periodId,
                $teacherId,
                $actorUserId
            ): TeacherDutyAssignment {
                $this->school($schoolId);

                $actor = $this->lockEligibleUser(
                    $schoolId,
                    $actorUserId,
                    'actor'
                );

                $period = $this->period(
                    $schoolId,
                    $periodId,
                    true
                );

                if (! $period->active) {
                    throw ValidationException::withMessages([
                        'period_id' => [
                            'Only a current teacher duty period may receive assignments.',
                        ],
                    ]);
                }

                $teacher = $this->lockEligibleTeacher(
                    $schoolId,
                    $teacherId
                );

                $duplicate = TeacherDutyAssignment::query()
                    ->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('duty_period_id', $period->id)
                    ->where('teacher_id', $teacher->id)
                    ->where('active', true)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'teacher_id' => [
                            'This teacher already has a current assignment for the duty period.',
                        ],
                    ]);
                }

                $assignment = new TeacherDutyAssignment;

                $assignment->school_id = $schoolId;
                $assignment->duty_period_id = $period->id;
                $assignment->teacher_id = $teacher->id;
                $assignment->active = true;
                $assignment->assigned_by = $actor->id;
                $assignment->ended_by = null;
                $assignment->ended_at = null;
                $assignment->end_reason = null;

                $assignment->save();

                return $assignment->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $this->translateConstraintViolation($exception);

            throw $exception;
        }
    }

    public function endAssignment(
        string $schoolId,
        string $assignmentId,
        string $actorUserId,
        ?string $reason = null
    ): TeacherDutyAssignment {
        $normalizedReason = $this->normalizeReason($reason);

        return DB::transaction(function () use (
            $schoolId,
            $assignmentId,
            $actorUserId,
            $normalizedReason
        ): TeacherDutyAssignment {
            $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $assignmentSnapshot = $this->assignment(
                $schoolId,
                $assignmentId
            );

            $period = $this->period(
                $schoolId,
                (string) $assignmentSnapshot->duty_period_id,
                true
            );

            $assignment = $this->assignment(
                $schoolId,
                $assignmentId,
                true
            );

            if (
                (string) $assignment->duty_period_id
                !== (string) $period->id
            ) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'The teacher duty assignment no longer belongs to the expected duty period.',
                    ],
                ]);
            }

            if (! $assignment->active) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'The teacher duty assignment has already ended.',
                    ],
                ]);
            }

            if (! $period->active) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'The teacher duty assignment belongs to an ended duty period.',
                    ],
                ]);
            }

            $assignment->active = false;
            $assignment->ended_by = $actor->id;
            $assignment->ended_at = now();
            $assignment->end_reason = $normalizedReason;

            $assignment->save();

            return $assignment->refresh();
        }, 3);
    }

    public function period(
        string $schoolId,
        string $periodId,
        bool $lock = false
    ): TeacherDutyPeriod {
        $query = TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($periodId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function currentPeriods(string $schoolId): Collection
    {
        return TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->orderBy('id')
            ->get();
    }

    public function periodHistory(string $schoolId): Collection
    {
        return TeacherDutyPeriod::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
    }

    public function assignment(
        string $schoolId,
        string $assignmentId,
        bool $lock = false
    ): TeacherDutyAssignment {
        $query = TeacherDutyAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($assignmentId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function currentAssignmentsForPeriod(
        string $schoolId,
        string $periodId
    ): Collection {
        $period = $this->period(
            $schoolId,
            $periodId
        );

        return TeacherDutyAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $period->id)
            ->where('active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function assignmentHistoryForPeriod(
        string $schoolId,
        string $periodId
    ): Collection {
        $period = $this->period(
            $schoolId,
            $periodId
        );

        return TeacherDutyAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $period->id)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
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
                    'The selected school user is not eligible for teacher duty roster administration.',
                ],
            ]);
        }

        return $user;
    }

    private function lockEligibleTeacher(
        string $schoolId,
        string $teacherId
    ): Teacher {
        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('id', $teacherId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();

        if (! $teacher) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'Only an active same-school teacher may receive a duty assignment.',
                ],
            ]);
        }

        $teacherUser = User::query()
            ->withoutGlobalScopes()
            ->where('id', $teacher->user_id)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->whereNull('suspended_at')
            ->lockForUpdate()
            ->first();

        if (! $teacherUser) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'The selected teacher does not have an eligible same-school user account.',
                ],
            ]);
        }

        return $teacher;
    }

    private function lockAcademicWeek(
        string $schoolId,
        string $academicWeekId
    ): AcademicWeek {
        $academicWeek = AcademicWeek::query()
            ->withoutGlobalScopes()
            ->where('id', $academicWeekId)
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first();

        if (! $academicWeek) {
            throw ValidationException::withMessages([
                'academic_week_id' => [
                    'The selected academic week does not belong to this school.',
                ],
            ]);
        }

        return $academicWeek;
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
                $school->timezone ?: config('app.timezone')
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

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $normalized = trim($reason);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 500) {
            throw ValidationException::withMessages([
                'reason' => [
                    'The end reason may not exceed 500 characters.',
                ],
            ]);
        }

        return $normalized;
    }

    private function translateConstraintViolation(
        QueryException $exception
    ): void {
        $sqlState = $exception->errorInfo[0] ?? null;

        if ($sqlState !== '23505') {
            return;
        }

        $message = (string) $exception->getMessage();

        if (
            str_contains(
                $message,
                'teacher_duty_assignments_active_identity_unique'
            )
        ) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'This teacher already has a current assignment for the duty period.',
                ],
            ]);
        }
    }
}
