<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boarding\BoardingReportOccurrenceRequest;
use App\Services\Boarding\BoardingReportOccurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardingReportOccurrenceController extends Controller
{
    public function __construct(private BoardingReportOccurrenceService $occurrences) {}

    public function index(Request $request, string $report): JsonResponse
    {
        return response()->json([
            'data' => $this->occurrences->read($request->user(), $report),
        ]);
    }

    public function store(
        BoardingReportOccurrenceRequest $request,
        string $revision
    ): JsonResponse {
        return response()->json([
            'data' => $this->occurrences->record(
                $request->user(),
                $revision,
                (int) $request->validated('version'),
                $this->content($request)
            ),
        ]);
    }

    public function correct(
        BoardingReportOccurrenceRequest $request,
        string $revision,
        string $event
    ): JsonResponse {
        return response()->json([
            'data' => $this->occurrences->correct(
                $request->user(),
                $revision,
                (int) $request->validated('version'),
                $event,
                (int) $request->validated('event_version'),
                $this->content($request),
                $request->validated('reason')
            ),
        ]);
    }

    public function void(
        BoardingReportOccurrenceRequest $request,
        string $revision,
        string $event
    ): JsonResponse {
        return response()->json([
            'data' => $this->occurrences->void(
                $request->user(),
                $revision,
                (int) $request->validated('version'),
                $event,
                (int) $request->validated('event_version'),
                $request->validated('reason')
            ),
        ]);
    }

    private function content(BoardingReportOccurrenceRequest $request): array
    {
        return array_intersect_key($request->validated(), array_flip([
            'category_id', 'occurred_at', 'description', 'actions_taken',
            'follow_up_required', 'follow_up_notes',
        ]));
    }
}
