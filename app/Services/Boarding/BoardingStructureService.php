<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardingStructureService
{
    public function hostel(
        string $schoolId,
        string $hostelId,
        bool $lock = false
    ): Hostel {
        $query = Hostel::query()
            ->withoutGlobalScopes()
            ->where('id', $hostelId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function room(
        string $schoolId,
        string $roomId,
        bool $lock = false
    ): HostelRoom {
        $query = HostelRoom::query()
            ->withoutGlobalScopes()
            ->where('id', $roomId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function bed(
        string $schoolId,
        string $bedId,
        bool $lock = false
    ): HostelBed {
        $query = HostelBed::query()
            ->withoutGlobalScopes()
            ->where('id', $bedId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function createHostel(
        string $schoolId,
        array $attributes
    ): Hostel {
        return DB::transaction(function () use (
            $schoolId,
            $attributes
        ): Hostel {
            DB::table('schools')
                ->where('id', $schoolId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertHostelType(
                (string) $attributes['hostel_type']
            );

            $this->assertPositiveCapacity(
                $attributes['capacity'] ?? null,
                'capacity'
            );

            $duplicate = Hostel::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('hostel_name', $attributes['hostel_name'])
                ->where('is_deleted', false)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'hostel_name' => [
                        'A hostel with this name already exists.',
                    ],
                ]);
            }

            $hostel = new Hostel;
            $hostel->school_id = $schoolId;
            $hostel->active = true;
            $hostel->is_deleted = false;
            $hostel->fill($attributes);
            $hostel->save();

            return $hostel->refresh();
        });
    }

    public function updateHostel(
        string $schoolId,
        string $hostelId,
        array $attributes
    ): Hostel {
        return DB::transaction(function () use (
            $schoolId,
            $hostelId,
            $attributes
        ): Hostel {
            $hostel = $this->hostel(
                $schoolId,
                $hostelId,
                true
            );

            if (array_key_exists('hostel_type', $attributes)) {
                $this->assertHostelType(
                    (string) $attributes['hostel_type']
                );
            }

            if (array_key_exists('capacity', $attributes)) {
                $this->assertPositiveCapacity(
                    $attributes['capacity'],
                    'capacity'
                );

                if ($attributes['capacity'] !== null) {
                    $activeBeds = HostelBed::query()
                        ->withoutGlobalScopes()
                        ->join(
                            'hostel_rooms',
                            'hostel_rooms.id',
                            '=',
                            'hostel_beds.room_id'
                        )
                        ->where(
                            'hostel_beds.school_id',
                            $schoolId
                        )
                        ->where(
                            'hostel_rooms.school_id',
                            $schoolId
                        )
                        ->where(
                            'hostel_rooms.hostel_id',
                            $hostel->id
                        )
                        ->where(
                            'hostel_beds.is_deleted',
                            false
                        )
                        ->where(
                            'hostel_beds.active',
                            true
                        )
                        ->where(
                            'hostel_rooms.is_deleted',
                            false
                        )
                        ->count();

                    if ((int) $attributes['capacity'] < $activeBeds) {
                        throw ValidationException::withMessages([
                            'capacity' => [
                                'Hostel capacity cannot be lower than the number of active beds.',
                            ],
                        ]);
                    }
                }
            }

            if (array_key_exists('hostel_name', $attributes)) {
                $duplicate = Hostel::query()
                    ->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where(
                        'hostel_name',
                        $attributes['hostel_name']
                    )
                    ->where('is_deleted', false)
                    ->where('id', '!=', $hostel->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'hostel_name' => [
                            'A hostel with this name already exists.',
                        ],
                    ]);
                }
            }

            $hostel->fill($attributes);
            $hostel->save();

            return $hostel->refresh();
        });
    }

    public function createRoom(
        string $schoolId,
        string $hostelId,
        array $attributes
    ): HostelRoom {
        return DB::transaction(function () use (
            $schoolId,
            $hostelId,
            $attributes
        ): HostelRoom {
            $hostel = $this->hostel(
                $schoolId,
                $hostelId,
                true
            );

            if (! $hostel->active) {
                throw ValidationException::withMessages([
                    'hostel' => [
                        'Rooms can only be added to an active hostel.',
                    ],
                ]);
            }

            $this->assertPositiveCapacity(
                $attributes['capacity'] ?? null,
                'capacity'
            );

            $duplicate = HostelRoom::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('hostel_id', $hostel->id)
                ->where('room_name', $attributes['room_name'])
                ->where('is_deleted', false)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'room_name' => [
                        'A room with this name already exists in the hostel.',
                    ],
                ]);
            }

            $room = new HostelRoom;
            $room->school_id = $schoolId;
            $room->hostel_id = $hostel->id;
            $room->active = true;
            $room->is_deleted = false;
            $room->fill($attributes);
            $room->save();

            return $room->refresh();
        });
    }

    public function updateRoom(
        string $schoolId,
        string $roomId,
        array $attributes
    ): HostelRoom {
        return DB::transaction(function () use (
            $schoolId,
            $roomId,
            $attributes
        ): HostelRoom {
            $room = $this->room(
                $schoolId,
                $roomId,
                true
            );

            if (array_key_exists('capacity', $attributes)) {
                $this->assertPositiveCapacity(
                    $attributes['capacity'],
                    'capacity'
                );

                if ($attributes['capacity'] !== null) {
                    $activeBeds = HostelBed::query()
                        ->withoutGlobalScopes()
                        ->where('school_id', $schoolId)
                        ->where('room_id', $room->id)
                        ->where('active', true)
                        ->where('is_deleted', false)
                        ->count();

                    if ((int) $attributes['capacity'] < $activeBeds) {
                        throw ValidationException::withMessages([
                            'capacity' => [
                                'Room capacity cannot be lower than the number of active beds.',
                            ],
                        ]);
                    }
                }
            }

            if (array_key_exists('room_name', $attributes)) {
                $duplicate = HostelRoom::query()
                    ->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('hostel_id', $room->hostel_id)
                    ->where(
                        'room_name',
                        $attributes['room_name']
                    )
                    ->where('is_deleted', false)
                    ->where('id', '!=', $room->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'room_name' => [
                            'A room with this name already exists in the hostel.',
                        ],
                    ]);
                }
            }

            $room->fill($attributes);
            $room->save();

            return $room->refresh();
        });
    }

    public function createBed(
        string $schoolId,
        string $roomId,
        array $attributes
    ): HostelBed {
        return DB::transaction(function () use (
            $schoolId,
            $roomId,
            $attributes
        ): HostelBed {
            $room = $this->room(
                $schoolId,
                $roomId,
                true
            );

            if (! $room->active) {
                throw ValidationException::withMessages([
                    'room' => [
                        'Beds can only be added to an active room.',
                    ],
                ]);
            }

            $hostel = $this->hostel(
                $schoolId,
                (string) $room->hostel_id,
                true
            );

            if (! $hostel->active) {
                throw ValidationException::withMessages([
                    'hostel' => [
                        'Beds can only be added to an active hostel.',
                    ],
                ]);
            }

            $roomBedCount = HostelBed::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('room_id', $room->id)
                ->where('active', true)
                ->where('is_deleted', false)
                ->count();

            if (
                $room->capacity !== null
                && $roomBedCount >= $room->capacity
            ) {
                throw ValidationException::withMessages([
                    'room' => [
                        'The room has reached its bed capacity.',
                    ],
                ]);
            }

            $hostelBedCount = HostelBed::query()
                ->withoutGlobalScopes()
                ->join(
                    'hostel_rooms',
                    'hostel_rooms.id',
                    '=',
                    'hostel_beds.room_id'
                )
                ->where(
                    'hostel_beds.school_id',
                    $schoolId
                )
                ->where(
                    'hostel_rooms.school_id',
                    $schoolId
                )
                ->where(
                    'hostel_rooms.hostel_id',
                    $hostel->id
                )
                ->where(
                    'hostel_beds.active',
                    true
                )
                ->where(
                    'hostel_beds.is_deleted',
                    false
                )
                ->where(
                    'hostel_rooms.is_deleted',
                    false
                )
                ->count();

            if (
                $hostel->capacity !== null
                && $hostelBedCount >= $hostel->capacity
            ) {
                throw ValidationException::withMessages([
                    'hostel' => [
                        'The hostel has reached its bed capacity.',
                    ],
                ]);
            }

            $duplicate = HostelBed::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('room_id', $room->id)
                ->where(
                    'bed_number',
                    $attributes['bed_number']
                )
                ->where('is_deleted', false)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'bed_number' => [
                        'A bed with this number already exists in the room.',
                    ],
                ]);
            }

            $bed = new HostelBed;
            $bed->school_id = $schoolId;
            $bed->room_id = $room->id;
            $bed->active = true;
            $bed->is_deleted = false;
            $bed->fill($attributes);
            $bed->save();

            return $bed->refresh();
        });
    }

    public function updateBed(
        string $schoolId,
        string $bedId,
        array $attributes
    ): HostelBed {
        return DB::transaction(function () use (
            $schoolId,
            $bedId,
            $attributes
        ): HostelBed {
            $bed = $this->bed(
                $schoolId,
                $bedId,
                true
            );

            if (array_key_exists('bed_number', $attributes)) {
                $duplicate = HostelBed::query()
                    ->withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('room_id', $bed->room_id)
                    ->where(
                        'bed_number',
                        $attributes['bed_number']
                    )
                    ->where('is_deleted', false)
                    ->where('id', '!=', $bed->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'bed_number' => [
                            'A bed with this number already exists in the room.',
                        ],
                    ]);
                }
            }

            $bed->fill($attributes);
            $bed->save();

            return $bed->refresh();
        });
    }

    public function archiveHostel(
        string $schoolId,
        string $hostelId,
        string $userId
    ): Hostel {
        return DB::transaction(function () use (
            $schoolId,
            $hostelId,
            $userId
        ): Hostel {
            $hostel = $this->hostel(
                $schoolId,
                $hostelId,
                true
            );

            $hasCurrentRooms = HostelRoom::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('hostel_id', $hostel->id)
                ->where('is_deleted', false)
                ->exists();

            if ($hasCurrentRooms) {
                throw ValidationException::withMessages([
                    'hostel' => [
                        'Archive the hostel rooms before archiving the hostel.',
                    ],
                ]);
            }

            $this->archiveModel(
                $hostel,
                $userId
            );

            return $hostel->refresh();
        });
    }

    public function archiveRoom(
        string $schoolId,
        string $roomId,
        string $userId
    ): HostelRoom {
        return DB::transaction(function () use (
            $schoolId,
            $roomId,
            $userId
        ): HostelRoom {
            $room = $this->room(
                $schoolId,
                $roomId,
                true
            );

            $hasCurrentBeds = HostelBed::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('room_id', $room->id)
                ->where('is_deleted', false)
                ->exists();

            if ($hasCurrentBeds) {
                throw ValidationException::withMessages([
                    'room' => [
                        'Archive the room beds before archiving the room.',
                    ],
                ]);
            }

            $this->archiveModel(
                $room,
                $userId
            );

            return $room->refresh();
        });
    }

    public function archiveBed(
        string $schoolId,
        string $bedId,
        string $userId
    ): HostelBed {
        return DB::transaction(function () use (
            $schoolId,
            $bedId,
            $userId
        ): HostelBed {
            $bed = $this->bed(
                $schoolId,
                $bedId,
                true
            );

            $hasActiveAllocation = DB::table('bed_allocations')
                ->where('school_id', $schoolId)
                ->where('bed_id', $bed->id)
                ->where('active', true)
                ->exists();

            if ($hasActiveAllocation) {
                throw ValidationException::withMessages([
                    'bed' => [
                        'A bed with an active learner allocation cannot be archived.',
                    ],
                ]);
            }

            $this->archiveModel(
                $bed,
                $userId
            );

            return $bed->refresh();
        });
    }

    private function archiveModel(
        Hostel|HostelRoom|HostelBed $model,
        string $userId
    ): void {
        $model->active = false;
        $model->is_deleted = true;
        $model->deleted_at = now();
        $model->deleted_by = $userId;
        $model->save();
    }

    private function assertHostelType(
        string $type
    ): void {
        if (! in_array($type, ['BOYS', 'GIRLS'], true)) {
            throw ValidationException::withMessages([
                'hostel_type' => [
                    'Hostel type must be BOYS or GIRLS.',
                ],
            ]);
        }
    }

    private function assertPositiveCapacity(
        mixed $capacity,
        string $field
    ): void {
        if ($capacity === null) {
            return;
        }

        if (
            ! is_numeric($capacity)
            || (int) $capacity <= 0
        ) {
            throw ValidationException::withMessages([
                $field => [
                    'Capacity must be greater than zero.',
                ],
            ]);
        }
    }
}
