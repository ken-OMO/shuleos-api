<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boarding\BoardingReportingObligationRequest;
use App\Services\Boarding\BoardingReportingObligationReadService;
use Illuminate\Http\JsonResponse;

class BoardingReportingObligationController extends Controller
{
    public function __construct(private BoardingReportingObligationReadService $obligations) {}

    public function index(BoardingReportingObligationRequest $request): JsonResponse
    {
        return response()->json(
            $this->obligations->read($request->user(), $request->validated())
        );
    }
}
