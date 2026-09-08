<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use App\Models\TeacherDutyOccurrence;
use App\Models\TeacherDutyOccurrenceCategory;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherDutyOccurrenceService
{
    public function recordOccurrence(
        string $schoolId,
        string $periodId,
        string $categoryId,
        string $occurrenceDate,
        ?string $occurrenceTime,
        string $description,
        string $actorUserId
    ): TeacherDutyOccurrence {
        return DB::transaction(function () use (
            $schoolId,
            $periodId,
            $categoryId,
            $occurrenceDate,
            $occurrenceTime,
            $description,
            $actorUserId
        ): TeacherDutyOccurrence {
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

            if (! $period->active) {
                throw ValidationException::withMessages([
                    'period_id' => [
                        'Only a current teacher duty period may receive occurrences.',
                    ],
                ]);
            }

            $category = $this->lockCategory(
                $schoolId,
                $categoryId
            );

            if (! $category->active) {
                throw ValidationException::withMessages([
                    'occurrence_category_id' => [
                        'Only an active teacher duty occurrence category may be used.',
                    ],
                ]);
            }

            $normalizedDate = $this->strictDate(
                $school,
                $occurrenceDate,
                'occurrence_date'
            );

            $periodStart = $period->start_date?->toDateString();
            $periodEnd = $period->end_date?->toDateString();

            if (
                $periodStart === null
                || $periodEnd === null
                || $normalizedDate < $periodStart
                || $normalizedDate > $periodEnd
            ) {
                throw ValidationException::withMessages([
                    'occurrence_date' => [
                        'The occurrence date must fall within the teacher duty period.',
                    ],
                ]);
            }

            $normalizedDescription = trim($description);

            if ($normalizedDescription === '') {
                throw ValidationException::withMessages([
                    'description' => [
                        'The occurrence description is required.',
                    ],
                ]);
            }

            $normalizedTime = $occurrenceTime === null
                ? null
                : trim($occurrenceTime);

            if ($normalizedTime === '') {
                $normalizedTime = null;
            }

            $occurrence = new TeacherDutyOccurrence;

            $occurrence->school_id = $schoolId;
            $occurrence->duty_period_id = $period->id;
            $occurrence->occurrence_category_id = $category->id;
            $occurrence->occurrence_date = $normalizedDate;
            $occurrence->occurrence_time = $normalizedTime;
            $occurrence->description = $normalizedDescription;
            $occurrence->recorded_by = $actor->id;

            $occurrence->save();

            return $occurrence->refresh();
        }, 3);
    }

    public function createCustomCategory(
        string $schoolId,
        string $code,
        string $name,
        ?string $description,
        int $displayOrder,
        string $actorUserId
    ): TeacherDutyOccurrenceCategory {
        return DB::transaction(function () use (
            $schoolId,
            $code,
            $name,
            $description,
            $displayOrder,
            $actorUserId
        ): TeacherDutyOccurrenceCategory {
            $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $normalizedCode = trim($code);

            $reservedCodes = [
                'discipline',
                'attendance',
                'health_safety',
                'cleanliness',
                'property_facilities',
                'academic',
                'visitor_security',
                'general',
            ];

            if (
                strlen($normalizedCode) > 100
                || preg_match(
                    '/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                    $normalizedCode
                ) !== 1
                || in_array(
                    $normalizedCode,
                    $reservedCodes,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'code' => [
                        'The category code is invalid or reserved.',
                    ],
                ]);
            }

            $normalizedName = trim($name);

            if (
                $normalizedName === ''
                || strlen($normalizedName) > 150
            ) {
                throw ValidationException::withMessages([
                    'name' => [
                        'The category name is required and may not exceed 150 characters.',
                    ],
                ]);
            }

            if ($displayOrder < 0) {
                throw ValidationException::withMessages([
                    'display_order' => [
                        'The display order must be non-negative.',
                    ],
                ]);
            }

            $normalizedDescription = $description === null
                ? null
                : trim($description);

            if ($normalizedDescription === '') {
                $normalizedDescription = null;
            }

            $duplicate = TeacherDutyOccurrenceCategory::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('code', $normalizedCode)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'code' => [
                        'The category code is already in use for this school.',
                    ],
                ]);
            }

            $category = new TeacherDutyOccurrenceCategory;

            $category->school_id = $schoolId;
            $category->code = $normalizedCode;
            $category->name = $normalizedName;
            $category->description = $normalizedDescription;
            $category->is_canonical = false;
            $category->display_order = $displayOrder;
            $category->active = true;
            $category->created_by = $actor->id;
            $category->deactivated_by = null;
            $category->deactivated_at = null;

            $category->save();

            return $category->refresh();
        }, 3);
    }

    public function deactivateCategory(
        string $schoolId,
        string $categoryId,
        string $actorUserId
    ): TeacherDutyOccurrenceCategory {
        return DB::transaction(function () use (
            $schoolId,
            $categoryId,
            $actorUserId
        ): TeacherDutyOccurrenceCategory {
            $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $category = $this->lockCategory(
                $schoolId,
                $categoryId
            );

            if (! $category->active) {
                throw ValidationException::withMessages([
                    'occurrence_category_id' => [
                        'The teacher duty occurrence category is already deactivated.',
                    ],
                ]);
            }

            $category->active = false;
            $category->deactivated_by = $actor->id;
            $category->deactivated_at = now();

            $category->save();

            return $category->refresh();
        }, 3);
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
                    'The selected school user is not eligible for teacher duty occurrence recording.',
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

    private function lockCategory(
        string $schoolId,
        string $categoryId
    ): TeacherDutyOccurrenceCategory {
        $category = TeacherDutyOccurrenceCategory::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($categoryId)
            ->lockForUpdate()
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'occurrence_category_id' => [
                    'The selected teacher duty occurrence category does not belong to this school.',
                ],
            ]);
        }

        return $category;
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
}
