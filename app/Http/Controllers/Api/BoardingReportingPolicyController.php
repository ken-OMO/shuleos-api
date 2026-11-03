<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boarding\BoardingReportingPolicyRequest;
use App\Services\Boarding\BoardingReportingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardingReportingPolicyController extends Controller
{
    public function __construct(private BoardingReportingPolicyService $policies) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->policies->read($request->user()),
        ]);
    }

    public function configure(BoardingReportingPolicyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $expectedRevision = (int) $data['expected_revision'];
        unset($data['expected_revision']);

        return response()->json([
            'data' => $this->policies->configure(
                $request->user(), $expectedRevision, $data
            ),
        ]);
    }

    public function materialize(BoardingReportingPolicyRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->policies->materializeToday($request->user()),
        ]);
    }
}
