<?php

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\StudentElectionBallot;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionVoter;
use App\Models\User;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudentElectionVotingService
{
    public function __construct(private readonly StudentElectionLearnerAccess $a) {}

    // Internal position-level primitive, not a classroom session authorisation.
    // The ordinary learner HTTP endpoint remains disabled.
    public function vote(User $u, string $election, string $position, string $candidate): array
    {
        $learner = $this->a->learner($u);

        return DB::transaction(function () use ($u, $learner, $election, $position, $candidate) {
            // Lock order: election SHARE, then this learner's position registry
            // row UPDATE. All application lifecycle/review writers take the
            // election UPDATE lock first; independent voters can coexist.
            $e = StudentElection::current()->whereKey($election)->where('school_id', $u->school_id)
                ->sharedLock()->first();
            if (! $e || $e->status !== 'voting_open') {
                throw ValidationException::withMessages(['election' => 'Election is not open for voting.']);
            }
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            if ($e->ballot_method !== 'legacy_single_selection') {
                throw ValidationException::withMessages(['vote' => 'Classroom elections require atomic complete-ballot submission.']);
            }
            $positionExists = $e->electionPositions()->whereKey($position)
                ->where('school_id', $u->school_id)->where('active', true)
                ->whereHas('position', fn ($q) => $q->where('school_id', $u->school_id)
                    ->where('active', true)->where('is_deleted', false))->exists();
            if (! $positionExists) {
                throw ValidationException::withMessages(['position' => 'Election position is unavailable.']);
            }
            $v = StudentElectionVoter::where('school_id', $u->school_id)->where('election_id', $e->id)
                ->where('election_position_id', $position)->where('learner_id', $learner->id)
                ->where('eligible', true)->lockForUpdate()->first();
            if (! $v || $v->has_voted) {
                throw ValidationException::withMessages(['vote' => 'Learner is ineligible or has already voted.']);
            }
            $freshUser = User::withoutGlobalScopes()->where('school_id', $u->school_id)->findOrFail($u->id);
            $freshLearner = $this->a->learner($freshUser);
            if ((string) $freshLearner->id !== (string) $learner->id) {
                throw ValidationException::withMessages(['vote' => 'Learner identity changed.']);
            }
            $c = StudentElectionCandidate::whereKey($candidate)->where('school_id', $u->school_id)
                ->where('election_position_id', $position)->where('status', 'approved')->where('is_deleted', false)
                ->whereHas('learner', fn ($q) => $q->where('school_id', $u->school_id)
                    ->where('active', true)->where('is_deleted', false))->first();
            if (! $c) {
                throw ValidationException::withMessages(['candidate' => 'Approved candidate is unavailable.']);
            }
            // Acceptance uses a fresh application-server clock reading after
            // every potentially blocking coordination lock. Window is [open, close).
            app(StudentElectionCommercialAccess::class)->live($e);
            $acceptedAt = now();
            if ($acceptedAt->lt($e->voting_opens_at) || $acceptedAt->gte($e->voting_closes_at)) {
                throw ValidationException::withMessages(['election' => 'Voting window is not active.']);
            }
            // This is a random uniqueness marker, never an authentication token
            // or proof of anonymity. It is not linked to the participation row.
            $ballot = StudentElectionBallot::create([
                'id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'election_id' => $e->id,
                'election_position_id' => $position, 'candidate_id' => $c->id,
                'anonymous_ballot_token_hash' => hash('sha256', random_bytes(32)),
                'ballot_status' => 'valid',
            ]);
            if (! $ballot->exists || ! $v->update([
                'has_voted' => true,
            ])) {
                throw new RuntimeException('Vote persistence failed.');
            }

            return ['confirmed' => true, 'election_id' => $e->id,
                'election_position_id' => $position];
        });
    }
}
