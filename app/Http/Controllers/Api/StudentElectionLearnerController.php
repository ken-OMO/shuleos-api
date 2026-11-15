<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\StudentElection\StudentElectionCandidateResource;
use App\Http\Resources\StudentElection\StudentElectionLeaderResource;
use App\Http\Resources\StudentElection\StudentElectionResource;
use App\Http\Resources\StudentElection\StudentElectionResultResource;
use App\Services\StudentElection\StudentElectionReadService;

class StudentElectionLearnerController extends BaseApiController
{
    public function __construct(private readonly StudentElectionReadService $reads) {}

    public function index()
    {
        return $this->success(StudentElectionResource::collection($this->reads->index(auth()->user())));
    }

    public function show(string $election)
    {
        return $this->success(new StudentElectionResource($this->reads->show(auth()->user(), $election)));
    }

    public function candidates(string $election, string $position)
    {
        return $this->success(StudentElectionCandidateResource::collection(
            $this->reads->candidates(auth()->user(), $election, $position)
        ));
    }

    public function vote()
    {
        abort(403, 'Voting requires an authorised classroom station.');
    }

    public function results(string $election)
    {
        return $this->success(StudentElectionResultResource::collection($this->reads->results(auth()->user(), $election)));
    }

    public function leaders()
    {
        return $this->success(StudentElectionLeaderResource::collection($this->reads->leaders(auth()->user())));
    }
}
