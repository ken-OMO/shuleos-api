<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentElectionCancellationController extends BaseApiController
{
    public function __construct(private readonly StudentElectionCancellationService $service) {}

    private function response(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $r, string $election): JsonResponse
    {
        return $this->response($this->service->history($r->user(), $election));
    }

    public function propose(Request $r, string $election): JsonResponse
    {
        $v = $r->validate(['reason' => 'required|string|min:20|max:2000', 'conflict_free' => 'required|accepted']);

        return $this->response($this->service->propose($r->user(), $election, $v['reason'], true));
    }

    public function decide(Request $r, string $election, string $proposal): JsonResponse
    {
        $v = $r->validate(['approve' => 'required|boolean', 'reason' => 'required|string|min:20|max:2000', 'conflict_free' => 'required|accepted']);

        return $this->response($this->service->decide($r->user(), $election, $proposal, (bool) $v['approve'], $v['reason'], true));
    }

    public function fresh(Request $r, string $election): JsonResponse
    {
        $v = $r->validate(['title' => 'required|string|max:255', 'academic_year_id' => 'required|uuid', 'term_id' => 'nullable|uuid',
            'voting_opens_at' => 'required|string', 'voting_closes_at' => 'required|string', 'conflict_free' => 'required|accepted']);

        return $this->response($this->service->fresh($r->user(), $election, $v, true));
    }
}
