<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\BedAllocation;
use App\Models\BedAllocationHistory;
use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Learner;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                $allocation->status = 'active';
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

    /**
     * Transfer foundation.
     *
     * This stage intentionally proves transaction rollback before the
     * successful destination-allocation path is enabled.
     */
    public function transfer(
        string $schoolId,
        string $allocationId,
        string $destinationBedId,
        string $userId,
        ?string $reason = null
    ): BedAllocation {
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => [
                    'The transfer reason may not exceed 500 characters.',
                ],
            ]);
        }
        try {
            return DB::transaction(function () use (
                $schoolId,
                $allocationId,
                $destinationBedId,
                $userId,
                $reason
            ): BedAllocation {
                $school = $this->school($schoolId);

                $actor = $this->lockEligibleActor(
                    $schoolId,
                    $userId
                );

                $source = $this->allocation(
                    $schoolId,
                    $allocationId,
                    true
                );

                if (
                    ! $source->active
                    || $source->status !== 'active'
                    || $source->release_date !== null
                ) {
                    throw ValidationException::withMessages([
                        'allocation_id' => [
                            'Only an active bed allocation can be transferred.',
                        ],
                    ]);
                }

                $learner = $this->lockEligibleLearner(
                    $schoolId,
                    (string) $source->learner_id
                );

                $lockedBeds = $this->lockTransferBeds(
                    $schoolId,
                    (string) $source->bed_id,
                    $destinationBedId
                );

                $sourceBed = $lockedBeds->get(
                    (string) $source->bed_id
                );

                $destinationBed = $lockedBeds->get(
                    $destinationBedId
                );

                if ($sourceBed === null || $destinationBed === null) {
                    throw ValidationException::withMessages([
                        'bed_id' => [
                            'The transfer bed resources could not be resolved.',
                        ],
                    ]);
                }

                $lockedRooms = $this->lockTransferRooms(
                    $schoolId,
                    [
                        (string) $sourceBed->room_id,
                        (string) $destinationBed->room_id,
                    ]
                );

                $sourceRoom = $lockedRooms->get(
                    (string) $sourceBed->room_id
                );

                $destinationRoom = $lockedRooms->get(
                    (string) $destinationBed->room_id
                );

                if ($sourceRoom === null || $destinationRoom === null) {
                    throw ValidationException::withMessages([
                        'bed_id' => [
                            'The transfer room resources could not be resolved.',
                        ],
                    ]);
                }

                $lockedHostels = $this->lockTransferHostels(
                    $schoolId,
                    [
                        (string) $sourceRoom->hostel_id,
                        (string) $destinationRoom->hostel_id,
                    ]
                );

                $sourceHostel = $lockedHostels->get(
                    (string) $sourceRoom->hostel_id
                );

                $destinationHostel = $lockedHostels->get(
                    (string) $destinationRoom->hostel_id
                );

                if (
                    $sourceHostel === null
                    || $destinationHostel === null
                ) {
                    throw ValidationException::withMessages([
                        'bed_id' => [
                            'The transfer hostel resources could not be resolved.',
                        ],
                    ]);
                }

                $this->assertHierarchy(
                    $schoolId,
                    $sourceBed,
                    $sourceRoom,
                    $sourceHostel
                );

                $this->assertHierarchy(
                    $schoolId,
                    $destinationBed,
                    $destinationRoom,
                    $destinationHostel
                );

                $this->assertGenderCompatibility(
                    $learner,
                    $destinationHostel
                );

                if (
                    (string) $source->bed_id
                    === (string) $destinationBed->id
                ) {
                    throw ValidationException::withMessages([
                        'bed_id' => [
                            'The destination bed must differ from the current bed.',
                        ],
                    ]);
                }

                $this->assertBedAvailable(
                    $schoolId,
                    (string) $destinationBed->id
                );

                $effectiveDate = CarbonImmutable::now(
                    $school->timezone ?: config('app.timezone')
                )->toDateString();

                /*
                 * Close the source first because the PostgreSQL
                 * active-learner partial unique index is intentionally
                 * non-deferrable. Any later failure rolls this mutation
                 * back with the rest of the transaction.
                 */
                $source->status = 'transferred';
                $source->active = false;
                $source->release_date = $effectiveDate;
                $source->save();

                /*
                 * A transfer always creates a new occupancy episode.
                 * The source allocation is never repurposed.
                 */
                $destination = new BedAllocation;

                $destination->school_id = $schoolId;
                $destination->learner_id = $learner->id;
                $destination->bed_id = $destinationBed->id;
                $destination->allocation_date = $effectiveDate;
                $destination->release_date = null;
                $destination->active = true;
                $destination->status = 'active';
                $destination->allocated_by = $actor->id;

                $destination->save();

                /*
                 * One transfer equals one immutable logical history event.
                 * event_id is intentionally separate from the history row
                 * primary key so correlation semantics remain explicit.
                 */
                $history = new BedAllocationHistory;

                $history->id = (string) Str::uuid();
                $history->school_id = $schoolId;
                $history->learner_id = $learner->id;
                $history->event_id = (string) Str::uuid();
                $history->event_type = 'transfer';
                $history->source_allocation_id = $source->id;
                $history->destination_allocation_id = $destination->id;
                $history->from_status = 'active';
                $history->to_status = 'transferred';
                $history->effective_date = $effectiveDate;
                $history->reason = $reason;
                $history->changed_by = $actor->id;
                $history->changed_at = now();
                $history->created_at = now();

                $history->save();

                return $destination->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $this->translateAllocationConstraintViolation(
                $exception
            );

            throw $exception;
        }
    }

    public function release(
        string $schoolId,
        string $allocationId,
        string $userId,
        ?string $reason = null
    ): BedAllocation {
        return DB::transaction(function () use (
            $schoolId,
            $allocationId,
            $userId,
            $reason
        ): BedAllocation {
            /*
             * Release is occupancy cleanup, not boarding admission.
             * Do not require the learner to remain active, lifecycle-active,
             * or currently classified as a boarder.
             */
            $school = $this->school($schoolId);
            $actor = $this->lockEligibleActor(
                $schoolId,
                $userId
            );
            $source = $this->allocation(
                $schoolId,
                $allocationId,
                true
            );

            if (
                $source->status !== 'active'
                || ! $source->active
                || $source->release_date !== null
            ) {
                throw ValidationException::withMessages([
                    'allocation_id' => [
                        'Only an active bed allocation can be released.',
                    ],
                ]);
            }

            /*
             * The history foreign key is tenant-aware, so verify that the
             * learner still belongs to this tenant without imposing current
             * boarding eligibility rules.
             */
            $learner = Learner::query()
                ->withoutGlobalScopes()
                ->where('id', $source->learner_id)
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reason !== null && mb_strlen($reason) > 500) {
                throw ValidationException::withMessages([
                    'reason' => [
                        'The release reason may not exceed 500 characters.',
                    ],
                ]);
            }

            $effectiveDate = CarbonImmutable::now(
                $school->timezone ?: config('app.timezone')
            )->toDateString();

            $source->status = 'released';
            $source->active = false;
            $source->release_date = $effectiveDate;
            $source->save();

            $history = new BedAllocationHistory;

            $history->id = (string) Str::uuid();
            $history->school_id = $schoolId;
            $history->learner_id = $learner->id;
            $history->event_id = (string) Str::uuid();
            $history->event_type = 'release';
            $history->source_allocation_id = $source->id;
            $history->destination_allocation_id = null;
            $history->from_status = 'active';
            $history->to_status = 'released';
            $history->effective_date = $effectiveDate;
            $history->reason = $reason;
            $history->changed_by = $actor->id;
            $history->changed_at = now();
            $history->created_at = now();

            $history->save();

            return $source->refresh();
        }, 3);
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

    /**
     * Read immutable lifecycle events connected to one tenant-owned
     * bed-allocation episode.
     */
    public function history(
        string $schoolId,
        string $allocationId
    ): Collection {
        BedAllocation::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($allocationId)
            ->firstOrFail();

        return BedAllocationHistory::query()
            ->where('school_id', $schoolId)
            ->where(function ($query) use ($allocationId): void {
                $query
                    ->where(
                        'source_allocation_id',
                        $allocationId
                    )
                    ->orWhere(
                        'destination_allocation_id',
                        $allocationId
                    );
            })
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();
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

    /**
     * Lock the source and destination beds in one deterministic order.
     */
    private function lockTransferBeds(
        string $schoolId,
        string $sourceBedId,
        string $destinationBedId
    ) {
        $bedIds = collect([
            $sourceBedId,
            $destinationBedId,
        ])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $beds = HostelBed::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', $bedIds)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (HostelBed $bed): string => (string) $bed->id);

        if ($beds->count() !== count($bedIds)) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'One or more transfer beds are unavailable.',
                ],
            ]);
        }

        foreach ($beds as $bed) {
            if (! $bed->active) {
                throw ValidationException::withMessages([
                    'bed_id' => [
                        'Only active beds can participate in a transfer.',
                    ],
                ]);
            }
        }

        return $beds;
    }

    /**
     * Lock all distinct rooms involved in the transfer in canonical order.
     *
     * @param  array<int, string>  $roomIds
     */
    private function lockTransferRooms(
        string $schoolId,
        array $roomIds
    ) {
        $roomIds = collect($roomIds)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $rooms = HostelRoom::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', $roomIds)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (HostelRoom $room): string => (string) $room->id);

        if ($rooms->count() !== count($roomIds)) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'One or more transfer rooms are unavailable.',
                ],
            ]);
        }

        foreach ($rooms as $room) {
            if (! $room->active) {
                throw ValidationException::withMessages([
                    'bed_id' => [
                        'Only beds in active rooms can participate in a transfer.',
                    ],
                ]);
            }
        }

        return $rooms;
    }

    /**
     * Lock all distinct hostels involved in the transfer in canonical order.
     *
     * @param  array<int, string>  $hostelIds
     */
    private function lockTransferHostels(
        string $schoolId,
        array $hostelIds
    ) {
        $hostelIds = collect($hostelIds)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $hostels = Hostel::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', $hostelIds)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Hostel $hostel): string => (string) $hostel->id);

        if ($hostels->count() !== count($hostelIds)) {
            throw ValidationException::withMessages([
                'bed_id' => [
                    'One or more transfer hostels are unavailable.',
                ],
            ]);
        }

        foreach ($hostels as $hostel) {
            if (! $hostel->active) {
                throw ValidationException::withMessages([
                    'bed_id' => [
                        'Only beds in active hostels can participate in a transfer.',
                    ],
                ]);
            }
        }

        return $hostels;
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
