<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\BedAllocation;
use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Learner;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BedAllocationService
{
    public function allocate(
        string $schoolId,
        string $learnerId,
        string $bedId,
        string $userId
    ): BedAllocation {
        try {
            return DB::transaction(function () use (
                $schoolId,
                $learnerId,
                $bedId,
                $userId
            ): BedAllocation {
                $school = $this->school(
                    $schoolId
                );

                $actor = $this->lockEligibleActor(
                    $schoolId,
                    $userId
                );

                $learner = $this->lockEligibleLearner(
                    $schoolId,
                    $learnerId
                );

                $bed = $this->lockEligibleBed(
                    $schoolId,
                    $bedId
                );

                $room = $this->lockEligibleRoom(
                    $schoolId,
                    (string) $bed->room_id
                );

                $hostel = $this->lockEligibleHostel(
                    $schoolId,
                    (string) $room->hostel_id
                );

                $this->assertHierarchy(
                    $schoolId,
                    $bed,
                    $room,
                    $hostel
                );

                $this->assertGenderCompatibility(
                    $learner,
                    $hostel
                );

                $this->assertLearnerAvailable(
                    $schoolId,
                    (string) $learner->id
                );

                $this->assertBedAvailable(
                    $schoolId,
                    (string) $bed->id
                );

                $allocation = new BedAllocation;

                /*
                 * These fields are server-owned and deliberately assigned
                 * explicitly rather than through mass assignment.
                 */
                $allocation->school_id = $schoolId;
                $allocation->learner_id = $learner->id;
                $allocation->bed_id = $bed->id;
                $allocation->allocation_date = CarbonImmutable::now(
                    $school->timezone ?: config('app.timezone')
                )->toDateString();
                $allocation->release_date = null;
                $allocation->active = true;
                $allocation->allocated_by = $actor->id;

                $allocation->save();

                return $allocation->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $this->translateAllocationConstraintViolation(
                $exception
            );

            throw $exception;
        }
    }

    public function allocation(
        string $schoolId,
        string $allocationId,
        bool $lock = false
    ): BedAllocation {
        $query = BedAllocation::query()
            ->withoutGlobalScopes()
            ->where('id', $allocationId)
            ->where('school_id', $schoolId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function school(
        string $schoolId
    ): School {
        return School::query()
            ->where('id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function lockEligibleActor(
        string $schoolId,
        string $userId
    ): User {
        return User::query()
            ->where('id', $userId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockEligibleLearner(
        string $schoolId,
        string $learnerId
    ): Learner {
        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->where('id', $learnerId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $learner->active) {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'Only an active learner can be allocated a bed.',
                ],
            ]);
        }

        if ($learner->lifecycle_status !== 'active') {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'Only a learner with active lifecycle status can be allocated a bed.',
                ],
            ]);
        }

        if ($learner->mode_of_study !== 'boarder') {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'Only a boarder can be allocated a bed.',
                ],
            ]);
        }

        if (! in_array(
            $learner->gender,
            [
                'Male',
                'Female',
            ],
            true
        )) {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'A canonical learner gender is required before bed allocation.',
                ],
            ]);
        }

        return $learner;
    }

    private function lockEligibleBed(
        string $schoolId,
        string $bedId
    ): HostelBed {
        $bed = HostelBed::query()
            ->withoutGlobalScopes()
            ->where('id', $bedId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $bed->active) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'Only an active bed can receive a learner.',
                ],
            ]);
        }

        return $bed;
    }

    private function lockEligibleRoom(
        string $schoolId,
        string $roomId
    ): HostelRoom {
        $room = HostelRoom::query()
            ->withoutGlobalScopes()
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $room->active) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected bed belongs to an inactive room.',
                ],
            ]);
        }

        return $room;
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
            ->firstOrFail();

        if (! $hostel->active) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected bed belongs to an inactive hostel.',
                ],
            ]);
        }

        return $hostel;
    }

    private function assertHierarchy(
        string $schoolId,
        HostelBed $bed,
        HostelRoom $room,
        Hostel $hostel
    ): void {
        if (
            (string) $bed->school_id !== $schoolId
            || (string) $room->school_id !== $schoolId
            || (string) $hostel->school_id !== $schoolId
            || (string) $bed->room_id !== (string) $room->id
            || (string) $room->hostel_id !== (string) $hostel->id
        ) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected bed hierarchy is invalid.',
                ],
            ]);
        }
    }

    private function assertGenderCompatibility(
        Learner $learner,
        Hostel $hostel
    ): void {
        $expectedHostelType = match ($learner->gender) {
            'Male' => 'BOYS',
            'Female' => 'GIRLS',

            default => null,
        };

        if (
            $expectedHostelType === null
            || $hostel->hostel_type !== $expectedHostelType
        ) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected hostel is not compatible with the learner gender.',
                ],
            ]);
        }
    }

    private function assertLearnerAvailable(
        string $schoolId,
        string $learnerId
    ): void {
        $exists = BedAllocation::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('learner_id', $learnerId)
            ->where('active', true)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'The learner already has an active bed allocation.',
                ],
            ]);
        }
    }

    private function assertBedAvailable(
        string $schoolId,
        string $bedId
    ): void {
        $exists = BedAllocation::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('bed_id', $bedId)
            ->where('active', true)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected bed already has an active learner allocation.',
                ],
            ]);
        }
    }

    private function translateAllocationConstraintViolation(
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
                'bed_allocations_active_learner_unique'
            )
        ) {
            throw ValidationException::withMessages([
                'learner_id' => [
                    'The learner already has an active bed allocation.',
                ],
            ]);
        }

        if (
            str_contains(
                $message,
                'bed_allocations_active_bed_unique'
            )
        ) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'The selected bed already has an active learner allocation.',
                ],
            ]);
        }
    }
}
