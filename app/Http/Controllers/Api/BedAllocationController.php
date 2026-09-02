<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\StoreBedAllocationRequest;
use App\Models\BedAllocation;
use App\Services\Boarding\BedAllocationService;
use Illuminate\Http\JsonResponse;

class BedAllocationController extends BoardingController
{
    private const MODULE = 'Boarding';

    public function __construct(
        private readonly BedAllocationService $allocations
    ) {}

    public function store(
        StoreBedAllocationRequest $request
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $validated = $request->validated();

        $allocation = $this->allocations->allocate(
            $schoolId,
            (string) $validated['learner_id'],
            (string) $validated['bed_id'],
            $this->userId($request)
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $allocation,
            null,
            $this->auditValues($allocation),
            'Allocated boarding hostel bed to learner.'
        );

        return response()->json([
            'message' => 'Bed allocated successfully.',
            'data' => $this->resource($allocation),
        ], 201);
    }

    private function resource(
        BedAllocation $allocation
    ): array {
        return [
            'id' => $allocation->id,
            'learner_id' => $allocation->learner_id,
            'bed_id' => $allocation->bed_id,
            'allocation_date' => $allocation
                ->allocation_date
                ?->toDateString(),
            'release_date' => $allocation
                ->release_date
                ?->toDateString(),
            'active' => $allocation->active,
            'allocated_by' => $allocation->allocated_by,
            'created_at' => $allocation->created_at,
            'updated_at' => $allocation->updated_at,
        ];
    }

    private function auditValues(
        BedAllocation $allocation
    ): array {
        return [
            'learner_id' => $allocation->learner_id,
            'bed_id' => $allocation->bed_id,
            'allocation_date' => $allocation
                ->allocation_date
                ?->toDateString(),
            'release_date' => $allocation
                ->release_date
                ?->toDateString(),
            'active' => $allocation->active,
            'allocated_by' => $allocation->allocated_by,
        ];
    }
}
