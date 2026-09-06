<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\StoreHostelBedRequest;
use App\Http\Requests\Boarding\UpdateHostelBedRequest;
use App\Models\HostelBed;
use App\Services\Boarding\BoardingStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostelBedController extends BoardingController
{
    private const MODULE = 'Boarding';

    public function __construct(
        private readonly BoardingStructureService $boarding
    ) {}

    public function index(
        Request $request,
        string $room
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $roomModel = $this->boarding->room(
            $schoolId,
            $room
        );

        $beds = HostelBed::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('room_id', $roomModel->id)
            ->where('is_deleted', false)
            ->orderBy('bed_number')
            ->get()
            ->map(
                fn (HostelBed $bed): array => $this->resource($bed)
            );

        return response()->json([
            'data' => $beds,
        ]);
    }

    public function show(
        Request $request,
        string $bed
    ): JsonResponse {
        $model = $this->boarding->bed(
            $this->schoolId($request),
            $bed
        );

        return response()->json([
            'data' => $this->resource($model),
        ]);
    }

    public function store(
        StoreHostelBedRequest $request,
        string $room
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $bed = $this->boarding->createBed(
            $schoolId,
            $room,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $bed,
            null,
            $this->auditValues($bed),
            'Created boarding hostel bed.'
        );

        return response()->json([
            'message' => 'Hostel bed created successfully.',
            'data' => $this->resource($bed),
        ], 201);
    }

    public function update(
        UpdateHostelBedRequest $request,
        string $bed
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->bed(
            $schoolId,
            $bed
        );

        $oldValues = $this->auditValues($current);

        $updated = $this->boarding->updateBed(
            $schoolId,
            $bed,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $updated,
            $oldValues,
            $this->auditValues($updated),
            'Updated boarding hostel bed.'
        );

        return response()->json([
            'message' => 'Hostel bed updated successfully.',
            'data' => $this->resource($updated),
        ]);
    }

    public function destroy(
        Request $request,
        string $bed
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->bed(
            $schoolId,
            $bed
        );

        $oldValues = $this->auditValues($current);

        $archived = $this->boarding->archiveBed(
            $schoolId,
            $bed,
            $this->userId($request)
        );

        $this->audit(
            $request,
            self::MODULE,
            'Archive',
            $archived,
            $oldValues,
            $this->auditValues($archived),
            'Archived boarding hostel bed.'
        );

        return response()->json([
            'message' => 'Hostel bed archived successfully.',
        ]);
    }

    private function resource(HostelBed $bed): array
    {
        return [
            'id' => $bed->id,
            'room_id' => $bed->room_id,
            'bed_number' => $bed->bed_number,
            'active' => $bed->active,
            'created_at' => $bed->created_at,
            'updated_at' => $bed->updated_at,
        ];
    }

    private function auditValues(HostelBed $bed): array
    {
        return [
            'room_id' => $bed->room_id,
            'bed_number' => $bed->bed_number,
            'active' => $bed->active,
            'is_deleted' => $bed->is_deleted,
        ];
    }
}
