<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Models\StudentElection;
use App\Models\StudentElectionResult;
use App\Models\StudentLeadershipPosition;
use App\Services\StudentElection\StudentElectionService;
use Illuminate\Http\Request;

class StudentElectionAdminController extends BaseApiController
{
    public function __construct(private readonly StudentElectionService $s) {}

    private function school(Request $r): string
    {
        $id = $r->attributes->get('tenant_school_id');
        abort_if(! $id, 403);

        return (string) $id;
    }

    public function index(Request $r)
    {
        return $this->success(StudentElection::current()->where('school_id', $this->school($r))->paginate(20));
    }

    public function create(Request $r)
    {
        $v = $r->validate(['academic_year_id' => 'required|uuid', 'term_id' => 'nullable|uuid', 'title' => 'required|string', 'voting_opens_at' => 'required|date', 'voting_closes_at' => 'required|date|after:voting_opens_at', 'results_visibility' => 'sometimes|in:hidden,after_close,after_approval,live_turnout_only', 'tie_break_rule' => 'sometimes|in:manual_runoff,joint_winners,leadership_decision']);

        return $this->created($this->s->create($this->school($r), $v, auth()->id()));
    }

    public function show(Request $r, string $election)
    {
        return $this->success(StudentElection::current()->whereKey($election)->where('school_id', $this->school($r))->with('electionPositions.position')->firstOrFail());
    }

    public function createPosition(Request $r)
    {
        $v = $r->validate(['name' => 'required|string', 'code' => 'nullable|string', 'scope' => 'required|in:school,grade,stream', 'grade_id' => 'nullable|uuid', 'stream_id' => 'nullable|uuid', 'gender_requirement' => 'nullable|string', 'number_of_winners' => 'integer|min:1']);

        return $this->created($this->s->position($this->school($r), $v, auth()->id()));
    }

    public function attach(Request $r, string $election)
    {
        $v = $r->validate(['position_id' => 'required|uuid', 'display_order' => 'sometimes|integer', 'number_of_winners' => 'nullable|integer|min:1', 'candidate_eligibility_rules' => 'nullable|array', 'voter_eligibility_rules' => 'nullable|array']);

        return $this->created($this->s->attach($this->school($r), $election, $v['position_id'], $v));
    }

    public function voters(Request $r, string $election)
    {
        return $this->success(['generated' => $this->s->generateVoters($this->school($r), $election)]);
    }

    public function transition(Request $r, string $election, string $to)
    {
        return $this->success($this->s->transition($this->school($r), $election, $to, auth()->id()));
    }

    public function review(Request $r, string $candidate, string $status)
    {
        $v = $r->validate(['comments' => 'nullable|string']);

        return $this->success($this->s->review($this->school($r), $candidate, $status, auth()->id(), $v['comments'] ?? null));
    }

    public function tally(Request $r, string $election)
    {
        return $this->success($this->s->tally($this->school($r), $election, auth()->id()));
    }

    public function publish(Request $r, string $election)
    {
        return $this->success($this->s->publish($this->school($r), $election, auth()->id()));
    }

    public function results(Request $r, string $election)
    {
        return $this->success(StudentElectionResult::where('school_id', $this->school($r))->where('election_id', $election)->get());
    }

    public function positions(Request $r)
    {
        return $this->success(StudentLeadershipPosition::current()->where('school_id', $this->school($r))->get());
    }
}
