<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boarding\BoardingDailyReportRequest;
use App\Services\Boarding\BoardingDailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardingDailyReportController extends Controller
{
    public function __construct(private BoardingDailyReportService $reports) {}

    public function show(Request $request, string $report): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->read($request->user(), $report),
        ]);
    }

    public function open(
        BoardingDailyReportRequest $request,
        string $obligation
    ): JsonResponse {
        return response()->json([
            'data' => $this->reports->open($request->user(), $obligation),
        ]);
    }

    public function updateDraft(
        BoardingDailyReportRequest $request,
        string $revision
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'data' => $this->reports->updateDraft(
                $request->user(),
                $revision,
                (int) $data['version'],
                [
                    'summary' => $data['summary'],
                    'no_occurrences' => $data['no_occurrences'],
                ]
            ),
        ]);
    }

    public function submit(
        BoardingDailyReportRequest $request,
        string $revision
    ): JsonResponse {
        return response()->json([
            'data' => $this->reports->submit(
                $request->user(),
                $revision,
                (int) $request->validated('version')
            ),
        ]);
    }

    public function correction(
        BoardingDailyReportRequest $request,
        string $report
    ): JsonResponse {
        return response()->json([
            'data' => $this->reports->openCorrection($request->user(), $report),
        ]);
    }

    public function review(
        BoardingDailyReportRequest $request,
        string $submission
    ): JsonResponse {
        return response()->json([
            'data' => $this->reports->review(
                $request->user(),
                $submission,
                $request->validated('decision'),
                $request->validated('comment')
            ),
        ]);
    }
}
