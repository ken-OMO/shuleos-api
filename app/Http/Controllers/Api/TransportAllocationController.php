<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportEnrolmentService;
use App\Services\Transport\TransportLearnerReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportAllocationController extends TransportController
{
    public function __construct(
        private TransportAssignmentService $assignments,
        private TransportLearnerReservationService $reservations,
        private TransportEnrolmentService $enrolments,
    ) {}

    public function showAssignment(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->assignments->show($this->actor($request), $id)]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->assignments->create($this->actor($request), $this->payload($request))], 201);
    }

    public function endAssignment(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->assignments->end($this->actor($request), $id, $this->payload($request))]);
    }

    public function showReservation(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->reservations->show($this->actor($request), $id)]);
    }

    public function storeReservation(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->reservations->create($this->actor($request), $this->payload($request))], 201);
    }

    public function endReservation(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->reservations->end($this->actor($request), $id, $this->payload($request))]);
    }

    public function showEnrolment(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->enrolments->show($this->actor($request), $id)]);
    }

    public function storeEnrolment(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->enrolments->create($this->actor($request), $this->payload($request))], 201);
    }

    public function transferEnrolment(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->enrolments->transfer($this->actor($request), $id, $this->payload($request))]);
    }
}
