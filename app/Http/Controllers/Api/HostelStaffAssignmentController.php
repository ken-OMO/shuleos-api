<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Boarding\EndHostelStaffAssignmentRequest;
use App\Http\Requests\Boarding\StoreHostelStaffAssignmentRequest;
use App\Models\HostelStaffAssignment;
use App\Services\Boarding\BoardingStaffResponsibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostelStaffAssignmentController extends BoardingController
{
    private const MODULE = 'Boarding';

    public function __construct(
        private readonly BoardingStaffResponsibilityService $responsibilities
    ) {}

    public function index(
        Request $request,
        string $hostel
    ): JsonResponse {
        $assignments = $this->responsibilities->currentForHostel(
            $this->schoolId($request),
            $hostel
        );

        return response()->json([
            'data' => $assignments
                ->map(
                    fn (
                        HostelStaffAssignment $assignment
                    ): array => $this->resource($assignment)
                )
                ->values(),
        ]);
    }

    public function store(
        StoreHostelStaffAssignmentRequest $request,
        string $hostel
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $validated = $request->validated();

        $assignment = $this->responsibilities->assign(
            $schoolId,
            $hostel,
            (string) $validated['user_id'],
            (string) $validated['responsibility_role'],
            isset($validated['effective_from'])
                ? (string) $validated['effective_from']
                : null,
            $this->userId($request)
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $assignment,
            null,
            $this->auditValues($assignment),
            'Assigned boarding hostel staff responsibility.'
        );

        return response()->json([
            'message' => 'Hostel staff responsibility assigned successfully.',
            'data' => $this->resource($assignment),
        ], 201);
    }

    public function history(
        Request $request,
        string $hostel
    ): JsonResponse {
        $assignments = $this->responsibilities->historyForHostel(
            $this->schoolId($request),
            $hostel
        );

        return response()->json([
            'data' => $assignments
                ->map(
                    fn (
                        HostelStaffAssignment $assignment
                    ): array => $this->resource($assignment)
                )
                ->values(),
        ]);
    }

    public function end(
        EndHostelStaffAssignmentRequest $request,
        string $assignment
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $current = $this->responsibilities->assignment(
            $schoolId,
            $assignment
        );

        $oldValues = $this->auditValues($current);
        $validated = $request->validated();

        $ended = $this->responsibilities->end(
            $schoolId,
            $assignment,
            $this->userId($request),
            isset($validated['reason'])
                ? (string) $validated['reason']
                : null
        );

        $this->audit(
            $request,
            self::MODULE,
            'End',
            $ended,
            $oldValues,
            $this->auditValues($ended),
            'Ended boarding hostel staff responsibility.'
        );

        return response()->json([
            'message' => 'Hostel staff responsibility ended successfully.',
            'data' => $this->resource($ended),
        ]);
    }

    private function resource(
        HostelStaffAssignment $assignment
    ): array {
        return [
            'id' => $assignment->id,
            'hostel_id' => $assignment->hostel_id,
            'user_id' => $assignment->user_id,
            'responsibility_role' => $assignment->responsibility_role,
            'effective_from' => $assignment
                ->effective_from
                ?->toDateString(),
            'effective_to' => $assignment
                ->effective_to
                ?->toDateString(),
            'active' => $assignment->active,
            'end_reason' => $assignment->end_reason,
            'ended_at' => $assignment->ended_at,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
        ];
    }

    private function auditValues(
        HostelStaffAssignment $assignment
    ): array {
        return [
            'hostel_id' => $assignment->hostel_id,
            'user_id' => $assignment->user_id,
            'responsibility_role' => $assignment->responsibility_role,
            'effective_from' => $assignment
                ->effective_from
                ?->toDateString(),
            'effective_to' => $assignment
                ->effective_to
                ?->toDateString(),
            'active' => $assignment->active,
            'end_reason' => $assignment->end_reason,
        ];
    }
}
