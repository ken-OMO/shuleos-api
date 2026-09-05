<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\ReleaseBedAllocationRequest;
use App\Http\Requests\Boarding\StoreBedAllocationRequest;
use App\Http\Requests\Boarding\TransferBedAllocationRequest;
use App\Models\BedAllocation;
use App\Models\BedAllocationHistory;
use App\Services\Boarding\BedAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function release(
        ReleaseBedAllocationRequest $request,
        string $allocation
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $validated = $request->validated();

        $released = $this->allocations->release(
            $schoolId,
            $allocation,
            $this->userId($request),
            isset($validated['reason'])
                ? (string) $validated['reason']
                : null
        );

        $this->audit(
            $request,
            self::MODULE,
            'Release',
            $released,
            null,
            $this->auditValues($released),
            'Released learner boarding bed allocation.'
        );

        return response()->json([
            'message' => 'Bed allocation released successfully.',
            'data' => $this->resource($released),
        ]);
    }

    public function transfer(
        TransferBedAllocationRequest $request,
        string $allocation
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $validated = $request->validated();

        $destination = $this->allocations->transfer(
            $schoolId,
            $allocation,
            (string) $validated['destination_bed_id'],
            $this->userId($request),
            isset($validated['reason'])
                ? (string) $validated['reason']
                : null
        );

        $this->audit(
            $request,
            self::MODULE,
            'Transfer',
            $destination,
            null,
            array_merge(
                $this->auditValues($destination),
                [
                    'source_allocation_id' => $allocation,
                ]
            ),
            'Transferred learner boarding bed allocation.'
        );

        return response()->json([
            'message' => 'Bed allocation transferred successfully.',
            'data' => $this->resource($destination),
        ]);
    }

    public function history(
        Request $request,
        string $allocation
    ): JsonResponse {
        $history = $this->allocations->history(
            $this->schoolId($request),
            $allocation
        );

        return response()->json([
            'data' => $history
                ->map(
                    fn (
                        BedAllocationHistory $event
                    ): array => $this->historyResource($event)
                )
                ->values(),
        ]);
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
            'status' => $allocation->status,
            'active' => $allocation->active,
            'allocated_by' => $allocation->allocated_by,
            'created_at' => $allocation->created_at,
            'updated_at' => $allocation->updated_at,
        ];
    }

    private function historyResource(
        BedAllocationHistory $history
    ): array {
        return [
            'id' => $history->id,
            'event_id' => $history->event_id,
            'event_type' => $history->event_type,
            'learner_id' => $history->learner_id,
            'source_allocation_id' => $history
                ->source_allocation_id,
            'destination_allocation_id' => $history
                ->destination_allocation_id,
            'from_status' => $history->from_status,
            'to_status' => $history->to_status,
            'effective_date' => $history
                ->effective_date
                ?->toDateString(),
            'reason' => $history->reason,
            'changed_by' => $history->changed_by,
            'changed_at' => $history->changed_at,
            'created_at' => $history->created_at,
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
            'status' => $allocation->status,
            'active' => $allocation->active,
            'allocated_by' => $allocation->allocated_by,
        ];
    }
}
