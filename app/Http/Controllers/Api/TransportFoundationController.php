<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportFoundationService;
use App\Services\Transport\TransportScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportFoundationController extends TransportController
{
    public function __construct(
        private TransportFoundationService $foundation,
        private TransportScheduleService $schedules,
    ) {}

    public function show(Request $request, string $resource, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->foundation->show($this->actor($request), $this->kind($resource), $id)]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {

        return response()->json(['data' => $this->foundation->create($this->actor($request), $this->kind($resource), $this->payload($request))], 201);
    }

    public function update(Request $request, string $resource, string $id): JsonResponse
    {

        return response()->json(['data' => $this->foundation->update($this->actor($request), $this->kind($resource), $id, $this->payload($request))]);
    }

    public function showSchedule(Request $request, string $id): JsonResponse
    {
        $this->emptyPayload($request);

        return response()->json(['data' => $this->schedules->show($this->actor($request), $id)]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {

        return response()->json(['data' => $this->schedules->create($this->actor($request), $this->payload($request))], 201);
    }

    public function scheduleState(Request $request, string $id): JsonResponse
    {

        return response()->json(['data' => $this->schedules->updateState($this->actor($request), $id, $this->payload($request))]);
    }

    private function kind(string $resource): string
    {
        return match ($resource) {
            'vehicles' => 'vehicle', 'routes' => 'route', 'stops' => 'stop',
            default => abort(404),
        };
    }
}
