<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionRoundService;
use Illuminate\Http\Request;

class StudentElectionRoundController extends BaseApiController
{
    public function __construct(private readonly StudentElectionRoundService $rounds) {}

    public function index(Request $request, string $election)
    {
        return $this->success($this->rounds->index($request->user(), $election));
    }

    public function create(Request $request, string $election)
    {
        $data = $request->validate(['election_position_id' => 'required|uuid',
            'opens_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'closes_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:opens_at']]);

        return $this->created($this->rounds->create($request->user(), $election,
            $data['election_position_id'], $data['opens_at'], $data['closes_at']));
    }

    public function transition(Request $request, string $election, string $round, string $phase)
    {
        return $this->success($this->rounds->transition($request->user(), $election, $round, $phase));
    }

    public function tally(Request $request, string $election, string $round)
    {
        return $this->success($this->rounds->tally($request->user(), $election, $round));
    }
}
