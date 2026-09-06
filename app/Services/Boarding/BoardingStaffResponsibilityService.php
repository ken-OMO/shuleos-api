<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\Hostel;
use App\Models\HostelStaffAssignment;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BoardingStaffResponsibilityService
{
    public function assign(
        string $schoolId,
        string $hostelId,
        string $userId,
        string $responsibilityRole,
        ?string $effectiveFrom,
        string $actorUserId
    ): HostelStaffAssignment {
        $role = trim($responsibilityRole);

        if ($role === '') {
            throw ValidationException::withMessages([
                'responsibility_role' => [
                    'The responsibility role is required.',
                ],
            ]);
        }

        if (mb_strlen($role) > 100) {
            throw ValidationException::withMessages([
                'responsibility_role' => [
                    'The responsibility role may not exceed 100 characters.',
                ],
            ]);
        }

        try {
            return DB::transaction(function () use (
                $schoolId,
                $hostelId,
                $userId,
                $role,
                $effectiveFrom,
                $actorUserId
            ): HostelStaffAssignment {
                $school = $this->school($schoolId);

                $actor = $this->lockEligibleUser(
                    $schoolId,
                    $actorUserId,
                    'actor'
                );

                $responsibleUser = $this->lockEligibleUser(
                    $schoolId,
                    $userId,
                    'user_id'
                );

                $hostel = $this->lockEligibleHostel(
                    $schoolId,
                    $hostelId
                );

                $effectiveDate = $this->effectiveFrom(
                    $school,
                    $effectiveFrom
                );

                $assignment = new HostelStaffAssignment;

                $assignment->school_id = $schoolId;
                $assignment->hostel_id = $hostel->id;
                $assignment->user_id = $responsibleUser->id;
                $assignment->responsibility_role = $role;
                $assignment->effective_from = $effectiveDate;
                $assignment->effective_to = null;
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

    public function end(
        string $schoolId,
        string $assignmentId,
        string $actorUserId,
        ?string $reason = null
    ): HostelStaffAssignment {
        $normalizedReason = $this->normalizeReason($reason);

        return DB::transaction(function () use (
            $schoolId,
            $assignmentId,
            $actorUserId,
            $normalizedReason
        ): HostelStaffAssignment {
            $school = $this->school($schoolId);

            $actor = $this->lockEligibleUser(
                $schoolId,
                $actorUserId,
                'actor'
            );

            $assignment = $this->assignment(
                $schoolId,
                $assignmentId,
                true
            );

            if (! $assignment->active) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'The responsibility assignment has already ended.',
                    ],
                ]);
            }

            $effectiveTo = $this->schoolLocalToday($school);

            if (
                $assignment->effective_from
                && $effectiveTo < $assignment->effective_from->toDateString()
            ) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'The responsibility assignment cannot end before it begins.',
                    ],
                ]);
            }

            $assignment->active = false;
            $assignment->effective_to = $effectiveTo;
            $assignment->ended_by = $actor->id;
            $assignment->ended_at = now();
            $assignment->end_reason = $normalizedReason;

            $assignment->save();

            return $assignment->refresh();
        }, 3);
    }

    public function assignment(
        string $schoolId,
        string $assignmentId,
        bool $lock = false
    ): HostelStaffAssignment {
        $query = HostelStaffAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($assignmentId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function currentForHostel(
        string $schoolId,
        string $hostelId
    ): Collection {
        $this->hostelForRead(
            $schoolId,
            $hostelId
        );

        return HostelStaffAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('hostel_id', $hostelId)
            ->where('active', true)
            ->orderBy('responsibility_role')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }

    public function historyForHostel(
        string $schoolId,
        string $hostelId
    ): Collection {
        $this->hostelForRead(
            $schoolId,
            $hostelId
        );

        return HostelStaffAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('hostel_id', $hostelId)
            ->orderByDesc('effective_from')
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
                    'The selected school user is not eligible for this responsibility.',
                ],
            ]);
        }

        return $user;
    }

    private function lockEligibleHostel(
        string $schoolId,
        string $hostelId
    ): Hostel {
        $hostel = Hostel::query()
            ->withoutGlobalScopes()
            ->where('id', $hostelId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();

        if (! $hostel || ! $hostel->active) {
            throw ValidationException::withMessages([
                'hostel_id' => [
                    'Only an active same-school hostel may receive a staff responsibility assignment.',
                ],
            ]);
        }

        return $hostel;
    }

    private function hostelForRead(
        string $schoolId,
        string $hostelId
    ): Hostel {
        return Hostel::query()
            ->withoutGlobalScopes()
            ->where('id', $hostelId)
            ->where('school_id', $schoolId)
            ->firstOrFail();
    }

    private function effectiveFrom(
        School $school,
        ?string $effectiveFrom
    ): string {
        $today = $this->schoolLocalToday($school);

        if ($effectiveFrom === null || trim($effectiveFrom) === '') {
            return $today;
        }

        $candidate = trim($effectiveFrom);

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
                'effective_from' => [
                    'The effective from date must use YYYY-MM-DD format.',
                ],
            ]);
        }

        if ($candidate > $today) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'The effective from date may not be in the future.',
                ],
            ]);
        }

        return $candidate;
    }

    private function schoolLocalToday(School $school): string
    {
        return CarbonImmutable::now(
            $school->timezone ?: config('app.timezone')
        )->toDateString();
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
                'hostel_staff_assignments_active_identity_unique'
            )
        ) {
            throw ValidationException::withMessages([
                'responsibility_role' => [
                    'This user already holds this current responsibility for the hostel.',
                ],
            ]);
        }
    }
}
