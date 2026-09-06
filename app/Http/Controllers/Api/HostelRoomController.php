<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\StoreHostelRoomRequest;
use App\Http\Requests\Boarding\UpdateHostelRoomRequest;
use App\Models\HostelRoom;
use App\Services\Boarding\BoardingStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostelRoomController extends BoardingController
{
    private const MODULE = 'Boarding';

    public function __construct(
        private readonly BoardingStructureService $boarding
    ) {}

    public function index(
        Request $request,
        string $hostel
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $hostelModel = $this->boarding->hostel(
            $schoolId,
            $hostel
        );

        $rooms = HostelRoom::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('hostel_id', $hostelModel->id)
            ->where('is_deleted', false)
            ->orderBy('room_name')
            ->get()
            ->map(
                fn (HostelRoom $room): array => $this->resource($room)
            );

        return response()->json([
            'data' => $rooms,
        ]);
    }

    public function show(
        Request $request,
        string $room
    ): JsonResponse {
        $model = $this->boarding->room(
            $this->schoolId($request),
            $room
        );

        return response()->json([
            'data' => $this->resource($model),
        ]);
    }

    public function store(
        StoreHostelRoomRequest $request,
        string $hostel
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $room = $this->boarding->createRoom(
            $schoolId,
            $hostel,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $room,
            null,
            $this->auditValues($room),
            'Created boarding hostel room.'
        );

        return response()->json([
            'message' => 'Hostel room created successfully.',
            'data' => $this->resource($room),
        ], 201);
    }

    public function update(
        UpdateHostelRoomRequest $request,
        string $room
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->room(
            $schoolId,
            $room
        );

        $oldValues = $this->auditValues($current);

        $updated = $this->boarding->updateRoom(
            $schoolId,
            $room,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $updated,
            $oldValues,
            $this->auditValues($updated),
            'Updated boarding hostel room.'
        );

        return response()->json([
            'message' => 'Hostel room updated successfully.',
            'data' => $this->resource($updated),
        ]);
    }

    public function destroy(
        Request $request,
        string $room
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->room(
            $schoolId,
            $room
        );

        $oldValues = $this->auditValues($current);

        $archived = $this->boarding->archiveRoom(
            $schoolId,
            $room,
            $this->userId($request)
        );

        $this->audit(
            $request,
            self::MODULE,
            'Archive',
            $archived,
            $oldValues,
            $this->auditValues($archived),
            'Archived boarding hostel room.'
        );

        return response()->json([
            'message' => 'Hostel room archived successfully.',
        ]);
    }

    private function resource(HostelRoom $room): array
    {
        return [
            'id' => $room->id,
            'hostel_id' => $room->hostel_id,
            'room_name' => $room->room_name,
            'floor_number' => $room->floor_number,
            'capacity' => $room->capacity,
            'active' => $room->active,
            'created_at' => $room->created_at,
            'updated_at' => $room->updated_at,
        ];
    }

    private function auditValues(HostelRoom $room): array
    {
        return [
            'hostel_id' => $room->hostel_id,
            'room_name' => $room->room_name,
            'floor_number' => $room->floor_number,
            'capacity' => $room->capacity,
            'active' => $room->active,
            'is_deleted' => $room->is_deleted,
        ];
    }
}
