<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportAttendanceService;
use App\Services\Transport\TransportTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportTripController extends TransportController
{
    public function __construct(
        private TransportTripService $trips,
        private TransportAttendanceService $attendance,
    ) {}

    public function capture(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->trips->capture($this->actor($request), $this->payload($request))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->trips->show($this->actor($request), $id)]);
    }

    public function attendance(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->attendance->showTrip($this->actor($request), $id)]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->attendance->completeTrip($this->actor($request), $id, $this->payload($request))]);
    }

    public function outcome(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->attendance->recordOutcome($this->actor($request), $id, $this->payload($request))], 201);
    }
}
