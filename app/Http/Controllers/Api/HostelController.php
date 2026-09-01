<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\StoreHostelRequest;
use App\Http\Requests\Boarding\UpdateHostelRequest;
use App\Models\Hostel;
use App\Services\Boarding\BoardingStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostelController extends BoardingController
{
    private const MODULE = 'Boarding';

    public function __construct(
        private readonly BoardingStructureService $boarding
    ) {}

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);

        $hostels = Hostel::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->orderBy('hostel_name')
            ->get()
            ->map(
                fn (Hostel $hostel): array => $this->resource($hostel)
            );

        return response()->json([
            'data' => $hostels,
        ]);
    }

    public function show(
        Request $request,
        string $hostel
    ): JsonResponse {
        $model = $this->boarding->hostel(
            $this->schoolId($request),
            $hostel
        );

        return response()->json([
            'data' => $this->resource($model),
        ]);
    }

    public function store(
        StoreHostelRequest $request
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $hostel = $this->boarding->createHostel(
            $schoolId,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $hostel,
            null,
            $this->auditValues($hostel),
            'Created boarding hostel.'
        );

        return response()->json([
            'message' => 'Hostel created successfully.',
            'data' => $this->resource($hostel),
        ], 201);
    }

    public function update(
        UpdateHostelRequest $request,
        string $hostel
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->hostel(
            $schoolId,
            $hostel
        );

        $oldValues = $this->auditValues($current);

        $updated = $this->boarding->updateHostel(
            $schoolId,
            $hostel,
            $request->validated()
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $updated,
            $oldValues,
            $this->auditValues($updated),
            'Updated boarding hostel.'
        );

        return response()->json([
            'message' => 'Hostel updated successfully.',
            'data' => $this->resource($updated),
        ]);
    }

    public function destroy(
        Request $request,
        string $hostel
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->boarding->hostel(
            $schoolId,
            $hostel
        );

        $oldValues = $this->auditValues($current);

        $archived = $this->boarding->archiveHostel(
            $schoolId,
            $hostel,
            $this->userId($request)
        );

        $this->audit(
            $request,
            self::MODULE,
            'Archive',
            $archived,
            $oldValues,
            $this->auditValues($archived),
            'Archived boarding hostel.'
        );

        return response()->json([
            'message' => 'Hostel archived successfully.',
        ]);
    }

    private function resource(Hostel $hostel): array
    {
        return [
            'id' => $hostel->id,
            'hostel_name' => $hostel->hostel_name,
            'hostel_type' => $hostel->hostel_type,
            'capacity' => $hostel->capacity,
            'active' => $hostel->active,
            'created_at' => $hostel->created_at,
            'updated_at' => $hostel->updated_at,
        ];
    }

    private function auditValues(Hostel $hostel): array
    {
        return [
            'hostel_name' => $hostel->hostel_name,
            'hostel_type' => $hostel->hostel_type,
            'capacity' => $hostel->capacity,
            'active' => $hostel->active,
            'is_deleted' => $hostel->is_deleted,
        ];
    }
}
