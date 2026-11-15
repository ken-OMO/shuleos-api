<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\StudentElection\StudentElectionStationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentElectionStationController extends BaseApiController
{
    public function __construct(private readonly StudentElectionStationService $stations) {}

    private function ownership(Request $r): void
    {
        if ($r->attributes->get('client_supplied_school_id') === true) {
            throw ValidationException::withMessages(['school_id' => 'The school_id field is prohibited.']);
        }
    }

    public function register(Request $r, string $election)
    {
        $this->ownership($r);
        $v = $r->validate(['teacher_assignment_id' => 'required|uuid', 'label' => 'required|string|max:60']);

        return $this->created($this->stations->register($r->user(), $election, $v['teacher_assignment_id'], $v['label']));
    }

    public function pair(Request $r, string $election, string $station)
    {
        return $this->success($this->stations->pair($r->user(), $election, $station));
    }

    public function queue(Request $r, string $election, string $assignment)
    {
        return $this->success($this->stations->queue($r->user(), $election, $assignment));
    }

    public function decide(Request $r, string $election, string $station, string $session)
    {
        $this->ownership($r);
        $v = $r->validate(['allow' => 'required|boolean', 'attendance_id' => 'required_if:allow,true|nullable|uuid']);

        return $this->success($this->stations->decide($r->user(), $election, $station, $session, (bool) $v['allow'], $v['attendance_id'] ?? null));
    }

    public function reset(Request $r, string $election, string $station)
    {
        return $this->success($this->stations->reset($r->user(), $election, $station));
    }

    public function revoke(Request $r, string $election, string $station)
    {
        return $this->success($this->stations->revoke($r->user(), $election, $station));
    }

    public function activate(Request $r)
    {
        $v = $r->validate(['station_id' => 'required|uuid', 'pairing_token' => 'required|string|size:64']);
        $result = $this->stations->activate($v['station_id'], $v['pairing_token']);
        $credential = $result['credential'];
        unset($result['credential']);

        return $this->success($result)->withCookie(cookie('shuleos_election_device', $credential, 360,
            '/api/election-station', null, true, true, false, 'strict'));
    }

    private function credential(Request $r): string
    {
        return (string) $r->cookie('shuleos_election_device', '');
    }

    public function requestVote(Request $r)
    {
        $v = $r->validate(['admission_no' => 'required|string|max:60', 'school_id' => 'prohibited', 'learner_id' => 'prohibited']);

        return $this->success($this->stations->request($this->credential($r), $v['admission_no']));
    }

    public function state(Request $r)
    {
        return $this->success($this->stations->state($this->credential($r)));
    }

    public function submit(Request $r)
    {
        $v = $r->validate(['session_id' => 'required|uuid', 'definition_digest' => 'required|string|size:64',
            'ballots' => 'required|array|min:1|max:100', 'ballots.*' => 'required|array:slot_id,choice,candidate_id', 'ballots.*.slot_id' => 'required|uuid',
            'ballots.*.choice' => 'required|in:selection,approve,reject,abstain', 'ballots.*.candidate_id' => 'nullable|uuid',
            'school_id' => 'prohibited', 'learner_id' => 'prohibited']);

        return $this->success($this->stations->submit($this->credential($r), $v['session_id'], $v['definition_digest'], $v['ballots']));
    }
}
