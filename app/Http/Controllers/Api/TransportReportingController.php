<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportReportingVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportReportingController extends TransportController
{
    public function __construct(
        private TransportReportingObligationService $obligations,
        private TransportReportingVisibilityService $visibility,
        private TransportDailyReportService $reports,
    ) {}

    public function configure(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->obligations->createPolicy($this->actor($request), $this->payload($request))], 201);
    }

    public function materialize(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->obligations->materialize($this->actor($request), $id)]);
    }

    public function obligation(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->obligations->show($this->actor($request), $id)]);
    }

    public function listing(Request $request): JsonResponse
    {

        return response()->json($this->visibility->listing($this->actor($request), $this->payload($request)));
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->reports->show($this->actor($request), $id)]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->reports->submit($this->actor($request), $id, $this->payload($request))], 201);
    }

    public function review(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->reports->review($this->actor($request), $id, $this->payload($request))], 201);
    }
}
