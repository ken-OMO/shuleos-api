<?php

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\StudentElectionBallot;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionVoter;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionVotingService
{
    public function __construct(private readonly LearnerPortalAccessService $a) {}

    public function vote(User $u, string $election, string $position, string $candidate): array
    {
        $learner = $this->a->learner($u);

        return DB::transaction(function () use ($u, $learner, $election, $position, $candidate) {
            $e = StudentElection::current()->whereKey($election)->where('school_id', $u->school_id)->lockForUpdate()->first();
            if (! $e || $e->status !== 'voting_open' || now()->lt($e->voting_opens_at) || now()->gt($e->voting_closes_at)) {
                throw ValidationException::withMessages(['election' => 'Election is not open for voting.']);
            }$v = StudentElectionVoter::where('school_id', $u->school_id)->where('election_id', $e->id)->where('election_position_id', $position)->where('learner_id', $learner->id)->where('eligible', true)->lockForUpdate()->first();
            if (! $v || $v->has_voted) {
                throw ValidationException::withMessages(['vote' => 'Learner is ineligible or has already voted.']);
            }$c = StudentElectionCandidate::whereKey($candidate)->where('school_id', $u->school_id)->where('election_position_id', $position)->where('status', 'approved')->where('is_deleted', false)->first();
            if (! $c) {
                throw ValidationException::withMessages(['candidate' => 'Approved candidate is unavailable.']);
            }$secret = Str::random(64);
            StudentElectionBallot::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'election_id' => $e->id, 'election_position_id' => $position, 'candidate_id' => $c->id, 'anonymous_ballot_token_hash' => Hash::make($secret), 'cast_at' => now(), 'ballot_status' => 'valid', 'created_at' => now()]);
            $v->update(['has_voted' => true, 'voted_at' => now(), 'voting_token_hash' => Hash::make(Str::random(64))]);

            return ['confirmed' => true, 'election_id' => $e->id, 'election_position_id' => $position, 'cast_at' => now()];
        });
    }
}
