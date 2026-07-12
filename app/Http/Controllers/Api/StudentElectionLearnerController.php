<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Models\StudentElection;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionResult;
use App\Models\StudentLeadershipTerm;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Http\Request;

class StudentElectionLearnerController extends BaseApiController
{
    public function __construct(private readonly LearnerPortalAccessService $a, private readonly StudentElectionVotingService $v) {}

    private function u()
    {
        return auth()->user();
    }

    public function index()
    {
        $u = $this->u();
        $this->a->learner($u);

        return $this->success(StudentElection::current()->where('school_id', $u->school_id)->whereIn('status', ['nominations_open', 'nominations_closed', 'campaigning', 'voting_open', 'voting_closed', 'published'])->get());
    }

    public function show(string $election)
    {
        $u = $this->u();
        $this->a->learner($u);

        return $this->success(StudentElection::current()->whereKey($election)->where('school_id', $u->school_id)->with('electionPositions.position')->firstOrFail());
    }

    public function candidates(string $election, string $position)
    {
        $u = $this->u();
        $this->a->learner($u);

        return $this->success(StudentElectionCandidate::where('school_id', $u->school_id)->where('election_position_id', $position)->where('status', 'approved')->where('is_deleted', false)->with('learner')->get());
    }

    public function vote(string $election, string $position, Request $r)
    {
        $v = $r->validate(['candidate_id' => 'required|uuid']);

        return $this->success($this->v->vote($this->u(), $election, $position, $v['candidate_id']), 'Vote recorded.');
    }

    public function results(string $election)
    {
        $u = $this->u();
        $this->a->learner($u);
        $e = StudentElection::current()->whereKey($election)->where('school_id', $u->school_id)->where('status', 'published')->firstOrFail();

        return $this->success(StudentElectionResult::where('election_id', $e->id)->whereIn('result_status', ['winner', 'not_elected', 'tied'])->get());
    }

    public function leaders()
    {
        $u = $this->u();
        $this->a->learner($u);

        return $this->success(StudentLeadershipTerm::current()->where('school_id', $u->school_id)->where('status', 'active')->with('learner', 'position')->get());
    }
}
