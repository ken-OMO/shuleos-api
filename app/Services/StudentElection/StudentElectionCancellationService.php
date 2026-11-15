<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentElectionCancellationService
{
    private function election(User $actor, string $id): StudentElection
    {
        abort_unless($actor->getAttribute('school_id') !== null, 403);

        return StudentElection::current()->where('school_id', $actor->school_id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function actor(User $actor, StudentElection $e, bool $conflictFree): User
    {
        $fresh = User::withoutGlobalScopes()->where('school_id', $e->school_id)->whereKey($actor->id)->sharedLock()->firstOrFail();
        $access = app(AuthContextService::class);
        $access->assertAccessible($fresh);
        abort_unless($access->hasPermission($fresh, 'manage_student_elections')
            && $access->hasPermission($fresh, 'publish_student_election_results'), 403);
        $school = DB::table('schools')->where('id', $e->school_id)->sharedLock()->firstOrFail();
        abort_if(in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true), 403);
        abort_unless($conflictFree, 403, 'An independent conflict declaration is required.');
        $learners = DB::table('student_election_candidates as c')
            ->join('student_election_positions as p', 'p.id', '=', 'c.election_position_id')
            ->where('p.election_id', $e->id)->where('c.school_id', $e->school_id)->pluck('c.learner_id');
        abort_if(DB::table('learners')->whereIn('id', $learners)->where('user_id', $fresh->id)->exists(), 403);
        // A known family relationship is a conflict even if its portal link is disabled.
        abort_if(DB::table('learner_parents as lp')->join('parents as p', 'p.id', '=', 'lp.parent_id')
            ->whereIn('lp.learner_id', $learners)->where('p.school_id', $e->school_id)->where('p.user_id', $fresh->id)->exists(), 403);

        return $fresh;
    }

    private function reason(string $reason): string
    {
        return validator(['reason' => trim($reason)], ['reason' => 'required|string|min:20|max:2000'])->validate()['reason'];
    }

    private function incidents(StudentElection $e): array
    {
        return DB::table('student_election_application_events as v')
            ->join('student_election_applications as a', 'a.id', '=', 'v.application_id')
            ->where('a.school_id', $e->school_id)->where('a.election_id', $e->id)->where('v.kind', 'incident')
            ->orderBy('v.id')->select('v.*')->get()->map(fn ($v) => (array) $v)->all();
    }

    private function digest(StudentElection $e): string
    {
        // No voter-to-ballot join or ballot ordering is generated for review.
        return hash('sha256', json_encode(['election' => $e->id, 'status' => $e->status,
            'hold' => (bool) $e->integrity_hold, 'incidents' => $this->incidents($e),
            'declarations' => DB::table('student_election_declarations')->where('election_id', $e->id)->orderBy('id')->pluck('id')->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function held(StudentElection $e): void
    {
        if (! $e->integrity_hold || ! in_array($e->status, ['voting_open', 'voting_closed', 'tallied', 'published'], true)
            || ! DB::table('student_election_electorates')->where('election_id', $e->id)->exists()
            || $this->incidents($e) === []) {
            throw ValidationException::withMessages(['cancellation' => 'A frozen election with a recorded integrity incident and active hold is required.']);
        }
    }

    private function audit(StudentElection $e, User $actor, string $action, string $id): void
    {
        if (! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $e->school_id,
            'election_id' => $e->id, 'actor_user_id' => $actor->id, 'action' => $action,
            'entity_type' => 'election_cancellation', 'entity_id' => $id, 'metadata' => '{}', 'created_at' => now()])) {
            throw new \RuntimeException('Cancellation audit failed.');
        }
    }

    public function propose(User $actor, string $election, string $reason, bool $conflictFree): array
    {
        return DB::transaction(function () use ($actor, $election, $reason, $conflictFree) {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, $e, $conflictFree);
            $this->held($e);
            $digest = $this->digest($e);
            $pending = DB::table('student_election_cancellation_proposals as p')
                ->where('p.election_id', $e->id)->where('p.evidence_digest', $digest)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_election_cancellation_decisions as d')->whereColumn('d.proposal_id', 'p.id'))->exists();
            abort_if($pending, 409, 'An independent decision on the current evidence is already pending.');
            $id = (string) Str::uuid();
            DB::table('student_election_cancellation_proposals')->insert(['id' => $id, 'school_id' => $e->school_id,
                'election_id' => $e->id, 'evidence_digest' => $digest, 'reason' => $this->reason($reason),
                'created_by' => $actor->id, 'created_at' => now()]);
            $this->audit($e, $actor, 'cancellation_proposed', $id);

            return ['proposal' => $id, 'evidence_digest' => $digest, 'status' => 'pending_review'];
        });
    }

    public function decide(User $actor, string $election, string $proposal, bool $approve, string $reason, bool $conflictFree): array
    {
        return DB::transaction(function () use ($actor, $election, $proposal, $approve, $reason, $conflictFree) {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, $e, $conflictFree);
            $this->held($e);
            $p = DB::table('student_election_cancellation_proposals')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $proposal)->firstOrFail();
            abort_if($p->created_by === $actor->id, 403, 'A different officer must review the cancellation.');
            abort_if($p->evidence_digest !== $this->digest($e), 409, 'Incident evidence changed; a fresh proposal is required.');
            abort_if(DB::table('student_election_cancellation_decisions')->where('proposal_id', $proposal)->exists(), 409, 'This proposal already has an immutable decision.');
            $reason = $this->reason($reason);
            // Match the publication lock order before changing current office occupancy.
            DB::table('student_leadership_positions')->where('school_id', $e->school_id)
                ->whereIn('id', $e->electionPositions()->pluck('position_id'))->orderBy('id')->lockForUpdate()->get();
            $terms = DB::table('student_leadership_terms')->where('school_id', $e->school_id)->where('election_id', $e->id)->orderBy('id')->lockForUpdate()->get();
            $id = (string) Str::uuid();
            DB::table('student_election_cancellation_decisions')->insert(['id' => $id, 'school_id' => $e->school_id,
                'election_id' => $e->id, 'proposal_id' => $proposal, 'approved' => $approve,
                'reason' => $reason, 'term_evidence' => $terms->toJson(), 'created_by' => $actor->id, 'created_at' => now()]);
            if ($approve) {
                // Preserve original appointment values in the immutable decision above.
                DB::table('student_leadership_terms')->where('school_id', $e->school_id)->where('election_id', $e->id)
                    ->where('status', 'active')->update(['status' => 'annulled', 'removal_reason' => 'Election cancelled by independent decision '.$id, 'updated_at' => now()]);
                if (! $e->update(['status' => 'cancelled', 'integrity_hold' => true])) {
                    throw new \RuntimeException('Cancellation persistence failed.');
                }
            }
            $this->audit($e, $actor, $approve ? 'cancellation_approved' : 'cancellation_rejected', $id);

            return ['decision' => $id, 'approved' => $approve, 'status' => $e->status, 'integrity_hold' => true];
        });
    }

    public function fresh(User $actor, string $election, array $data, bool $conflictFree): array
    {
        return DB::transaction(function () use ($actor, $election, $data, $conflictFree) {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, $e, $conflictFree);
            abort_unless($e->status === 'cancelled' && $e->integrity_hold, 409);
            $decision = DB::table('student_election_cancellation_decisions')->where('election_id', $e->id)->where('approved', true)->first();
            abort_unless($decision !== null, 409);
            abort_if(DB::table('student_election_replacements')->where('original_election_id', $e->id)->exists(), 409, 'A fresh election is already linked.');
            $v = validator($data, ['title' => 'required|string|max:255', 'academic_year_id' => 'required|uuid', 'term_id' => 'nullable|uuid',
                'voting_opens_at' => 'required|string', 'voting_closes_at' => 'required|string'])->validate();
            $dates = [];
            foreach (['voting_opens_at', 'voting_closes_at'] as $key) {
                if (! preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/D', $v[$key])) {
                    throw ValidationException::withMessages([$key => 'An explicit ISO-8601 offset is required.']);
                }
                try {
                    $dates[$key] = CarbonImmutable::parse($v[$key])->utc();
                } catch (\Exception) {
                    throw ValidationException::withMessages([$key => 'Invalid date.']);
                }
            }
            if ($dates['voting_opens_at']->lte(now()) || $dates['voting_closes_at']->lte($dates['voting_opens_at'])) {
                throw ValidationException::withMessages(['window' => 'A fresh future voting window is required.']);
            }
            // Explicit whitelist: no status, actor, parent policy, candidates or approvals can be inherited.
            $new = app(StudentElectionService::class)->create($e->school_id, ['title' => $v['title'], 'academic_year_id' => $v['academic_year_id'],
                'term_id' => $v['term_id'] ?? null, 'voting_opens_at' => $dates['voting_opens_at']->format('Y-m-d H:i:s'),
                'voting_closes_at' => $dates['voting_closes_at']->format('Y-m-d H:i:s')], $actor->id);
            // Deliberately copy no positions: school officers explicitly configure current offices and fresh policy.
            $id = (string) Str::uuid();
            DB::table('student_election_replacements')->insert(['id' => $id, 'school_id' => $e->school_id, 'original_election_id' => $e->id,
                'fresh_election_id' => $new->id, 'decision_id' => $decision->id, 'created_by' => $actor->id, 'created_at' => now()]);
            $this->audit($e, $actor, 'fresh_election_linked', $id);

            return ['election_id' => $new->id, 'original_election_id' => $e->id, 'status' => 'draft'];
        });
    }

    public function history(User $actor, string $election): array
    {
        return DB::transaction(function () use ($actor, $election) {
            $e = $this->election($actor, $election);
            $this->actor($actor, $e, true);

            return ['election_id' => $e->id, 'status' => $e->status, 'integrity_hold' => (bool) $e->integrity_hold,
                'proposals' => DB::table('student_election_cancellation_proposals')->where('election_id', $e->id)->orderBy('created_at')->get(['id', 'evidence_digest', 'reason', 'created_by', 'created_at'])->all(),
                'decisions' => DB::table('student_election_cancellation_decisions')->where('election_id', $e->id)->get(['id', 'proposal_id', 'approved', 'reason', 'created_by', 'created_at'])->all(),
                'replacement' => DB::table('student_election_replacements')->where('original_election_id', $e->id)->value('fresh_election_id')];
        });
    }
}
