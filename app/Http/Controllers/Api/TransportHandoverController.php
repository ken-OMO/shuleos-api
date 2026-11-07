<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportExceptionService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportHandoverController extends TransportController
{
    public function __construct(
        private TransportHandoverAuthorizationService $authorizations,
        private TransportJourneyEventService $events,
        private TransportExceptionService $exceptions,
    ) {}

    public function storeAuthorization(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->authorizations->create($this->actor($request), $this->payload($request))], 201);
    }

    public function showAuthorization(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->authorizations->show($this->actor($request), $id)]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->authorizations->revoke($this->actor($request), $id, $this->payload($request))]);
    }

    public function journey(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->events->show($this->actor($request), $id)]);
    }

    public function record(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->events->record($this->actor($request), $id, $this->payload($request))], 201);
    }

    public function exception(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->exceptions->show($this->actor($request), $id)]);
    }

    public function append(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->exceptions->append($this->actor($request), $id, $this->payload($request))], 201);
    }
}
