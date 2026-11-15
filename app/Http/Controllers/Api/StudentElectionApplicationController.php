<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionGuardianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentElectionApplicationController extends BaseApiController
{
    public function __construct(private readonly StudentElectionApplicationService $service) {}

    private function applicationResponse(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function publishedPolicy(Request $r, string $election): JsonResponse
    {
        return $this->applicationResponse($this->service->publishedPolicy($r->user(), $election));
    }

    public function policy(Request $r, string $election): JsonResponse
    {
        $v = $r->validate(['expected_policy' => 'nullable|uuid', 'terms' => 'required|string|min:20|max:20000', 'photo_required' => 'required|boolean',
            'reviewer_id' => 'required|uuid', 'appeal_reviewer_id' => 'required|uuid|different:reviewer_id',
            'opens_at' => 'required|string', 'applications_close_at' => 'required|string', 'consent_close_at' => 'required|string',
            'reviews_close_at' => 'required|string', 'appeals_close_at' => 'required|string']);

        return $this->applicationResponse($this->service->publishPolicy($r->user(), $election, $v['expected_policy'] ?? null, $v));
    }

    public function submit(Request $r, string $election): JsonResponse
    {
        $v = $r->validate(['expected_version' => 'nullable|uuid', 'election_position_id' => 'required|uuid', 'manifesto' => 'required|string|min:20|max:10000',
            'slogan' => 'required|string|max:160', 'policy_version' => 'required|uuid', 'accept_terms' => 'required|accepted', 'photo_path' => 'prohibited', 'photo_version' => 'nullable|uuid']);

        return $this->applicationResponse($this->service->submit($r->user(), $election, $v['expected_version'] ?? null, $v));
    }

    public function view(Request $r, string $election, string $application): JsonResponse
    {
        return $this->applicationResponse($this->service->view($r->user(), $election, $application));
    }

    public function consent(Request $r, string $election, string $application): JsonResponse
    {
        $v = $r->validate(['version' => 'required|uuid', 'designation' => 'required|uuid', 'decision' => 'required|in:accept,refuse,withdraw']);

        return $this->applicationResponse($this->service->consent($r->user(), $election, $application, $v['version'], $v['designation'], $v['decision']));
    }

    public function review(Request $r, string $election, string $application): JsonResponse
    {
        $v = $r->validate(['version' => 'required|uuid', 'approve' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000', 'appeal' => 'sometimes|boolean', 'conflict_free' => 'required|accepted']);

        return $this->applicationResponse($this->service->review($r->user(), $election, $application, $v['version'], (bool) $v['approve'], $v['reason'], (bool) ($v['appeal'] ?? false), true));
    }

    public function appeal(Request $r, string $election, string $application): JsonResponse
    {
        $v = $r->validate(['version' => 'required|uuid', 'reason' => 'required|string|min:10|max:2000']);

        return $this->applicationResponse($this->service->appeal($r->user(), $election, $application, $v['version'], $v['reason']));
    }

    public function withdraw(Request $r, string $election, string $application): JsonResponse
    {
        $v = $r->validate(['version' => 'required|uuid', 'reason' => 'required|string|min:10|max:2000']);

        return $this->applicationResponse($this->service->withdraw($r->user(), $election, $application, $v['version'], $v['reason']));
    }

    public function guardian(Request $r, string $election, string $learner): JsonResponse
    {
        $v = $r->validate(['parent_id' => 'required|uuid', 'expected_version' => 'nullable|uuid', 'reason' => 'required|string|min:10|max:1000']);

        return $this->applicationResponse(app(StudentElectionGuardianService::class)->designate($r->user(), $election, $learner, $v['parent_id'], $v['expected_version'] ?? null, $v['reason']));
    }
}
