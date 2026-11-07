<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Transport\TransportCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportCatalogueController extends TransportController
{
    public function __construct(private TransportCatalogueService $catalogue) {}

    public function index(Request $request, string $resource): JsonResponse
    {
        return response()->json($this->catalogue->listing($this->actor($request), $resource, $this->payload($request)));
    }
}
