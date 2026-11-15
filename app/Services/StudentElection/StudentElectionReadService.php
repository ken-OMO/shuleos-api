<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\Learner;
use App\Models\StudentElection;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionPosition;
use App\Models\StudentElectionResult;
use App\Models\StudentElectionVoter;
use App\Models\StudentLeadershipTerm;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentElectionReadService
{
    private const VISIBLE = ['nominations_open', 'nominations_closed', 'campaigning', 'voting_open', 'voting_closed', 'tallied', 'published'];

    public function __construct(
        private readonly StudentElectionLearnerAccess $access,
        private readonly AuthContextService $auth
    ) {}

    private function learner(User $user): Learner
    {
        $this->auth->assertAccessible($user);

        return $this->access->learner($user);
    }

    // Only currently implemented scopes are exposed. Unknown eligibility rules
    // and grade ranges fail closed until the full eligibility policy is built.
    private function scopePosition(Builder|Relation $query, Learner $learner): void
    {
        $query->where('school_id', $learner->school_id)
            ->where('active', true)->where('is_deleted', false)
            ->whereNull('minimum_grade_id')->whereNull('maximum_grade_id')
            ->where(fn ($q) => $q->whereNull('grade_id')->orWhere('grade_id', $learner->grade_id))
            ->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $learner->stream_id))
            ->where(function ($q) use ($learner) {
                $q->where('scope', 'school')
                    ->orWhere(fn ($grade) => $grade->where('scope', 'grade')->where('grade_id', $learner->grade_id))
                    ->orWhere(fn ($stream) => $stream->where('scope', 'stream')->where('stream_id', $learner->stream_id));
            });
    }

    private function scopeElectionPosition(Builder $query, Learner $learner): void
    {
        $query->where('school_id', $learner->school_id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('voter_eligibility_rules')
                ->orWhereRaw("voter_eligibility_rules = '[]'::jsonb")
                ->orWhereRaw("voter_eligibility_rules = '{}'::jsonb"))
            ->whereHas('position', fn ($q) => $this->scopePosition($q, $learner));
    }

    /** @return Builder<StudentElection> */
    private function electionQuery(Learner $learner): Builder
    {
        return StudentElection::current()->where('school_id', $learner->school_id)
            ->whereIn('status', self::VISIBLE)
            ->whereHas('electionPositions', fn ($q) => $this->scopeElectionPosition($q, $learner));
    }

    private function withPositions(StudentElection $election, Learner $learner): StudentElection
    {
        return $election->load(['electionPositions' => fn ($q) => $q
            ->where(fn ($ep) => $this->scopeElectionPosition($ep, $learner))
            ->with(['position' => fn ($p) => $this->scopePosition($p, $learner)])]);
    }

    public function index(User $user): Collection
    {
        $learner = $this->learner($user);

        return $this->electionQuery($learner)->get()
            ->map(fn ($election) => $this->withPositions($election, $learner));
    }

    public function show(User $user, string $id): StudentElection
    {
        $learner = $this->learner($user);

        return $this->withPositions($this->electionQuery($learner)->whereKey($id)->firstOrFail(), $learner);
    }

    public function candidates(User $user, string $id, string $position): Collection
    {
        return DB::transaction(function () use ($user, $id, $position) {
            app(StudentElectionCommercialAccess::class)->normal($user->school_id);
            $learner = $this->learner($user);
            $election = $this->electionQuery($learner)->whereKey($id)
                ->whereIn('status', ['campaigning', 'voting_open', 'voting_closed', 'tallied'])->firstOrFail();
            if ($election->application_method === 'versioned_v1' || $election->ballot_method === 'classroom_single_seat_v1') {
                $timezone = app(StudentElectionClassroomAccess::class)->timezone($election);
                abort_unless($election->status === 'campaigning' && ! $election->integrity_hold
                    && now($timezone)->toDateString() < $election->voting_opens_at->copy()->setTimezone($timezone)->toDateString(), 403,
                    'Campaign information is unavailable on voting day or while the election is paused.');
            }
            $ep = StudentElectionPosition::where('election_id', $election->id)->whereKey($position)
                ->where(fn ($q) => $this->scopeElectionPosition($q, $learner))->firstOrFail();

            abort_if(StudentElectionVoter::where('school_id', $user->school_id)
                ->where('election_id', $election->id)->where('learner_id', $learner->id)
                ->where('has_voted', true)->exists(), 403, 'Your vote has been recorded.');

            return StudentElectionCandidate::where('school_id', $user->school_id)
                ->where('election_position_id', $ep->id)->where('status', 'approved')->where('is_deleted', false)
                ->whereHas('learner', fn ($q) => $q->where('school_id', $user->school_id)
                    ->where('active', true)->where('is_deleted', false))
                ->with(['learner' => fn ($q) => $q->where('school_id', $user->school_id)
                    ->select(['id', 'first_name', 'middle_name', 'last_name'])])->get();
        });
    }

    public function results(User $user, string $id): Collection
    {
        $learner = $this->learner($user);
        $election = $this->electionQuery($learner)->whereKey($id)->where('status', 'published')->firstOrFail();
        $positions = StudentElectionPosition::where('election_id', $election->id)
            ->where(fn ($q) => $this->scopeElectionPosition($q, $learner))->pluck('id');

        $authorized = StudentElectionResult::where('school_id', $user->school_id)->where('election_id', $election->id)
            ->whereIn('election_position_id', $positions)
            ->whereExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('student_election_candidates as c')
                    ->whereColumn('c.id', 'student_election_results.candidate_id')
                    ->whereColumn('c.election_position_id', 'student_election_results.election_position_id')
                    ->where('c.school_id', $user->school_id)->where('c.is_deleted', false);
            })->get();
        $ids = $authorized->pluck('id')->all();

        return app(StudentElectionResultService::class)->effectiveRows((string) $user->school_id, $election->id)
            ->filter(fn ($r) => in_array($r->id, $ids, true) && in_array($r->result_status, ['winner', 'not_elected', 'tied'], true))->values();
    }

    public function leaders(User $user): Collection
    {
        $learner = $this->learner($user);

        return StudentLeadershipTerm::current()->where('school_id', $user->school_id)->where('status', 'active')
            ->whereHas('position', fn ($q) => $this->scopePosition($q, $learner))
            ->whereHas('learner', fn ($q) => $q->where('school_id', $user->school_id)
                ->where('active', true)->where('is_deleted', false))
            ->where(fn ($q) => $q->whereNull('election_id')->orWhereExists(function ($e) use ($user) {
                $e->selectRaw('1')->from('student_elections as e')
                    ->whereColumn('e.id', 'student_leadership_terms.election_id')
                    ->where('e.school_id', $user->school_id)->where('e.status', 'published')->where('e.is_deleted', false);
            }))
            ->with(['learner' => fn ($q) => $q->where('school_id', $user->school_id)
                ->select(['id', 'first_name', 'middle_name', 'last_name']),
                'position' => fn ($q) => $this->scopePosition($q, $learner)])->get();
    }
}
