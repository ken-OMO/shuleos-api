<?php

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionPosition;
use App\Models\StudentLeadershipPosition;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionService
{
    public function create(string $s, array $d, string $u): StudentElection
    {
        return DB::transaction(function () use ($s, $d, $u) {
            app(StudentElectionSettingsPolicy::class)->validate($d);
            app(StudentElectionCommercialAccess::class)->normal($s);
            if (! DB::table('academic_years')->where('id', $d['academic_year_id'])->where('school_id', $s)->exists()) {
                throw ValidationException::withMessages(['academic_year_id' => 'Academic year does not belong to this school.']);
            }
            if (! empty($d['term_id']) && ! DB::table('terms')->where('id', $d['term_id'])
                ->where('school_id', $s)->where('academic_year_id', $d['academic_year_id'])->exists()) {
                throw ValidationException::withMessages(['term_id' => 'Term does not belong to this school and academic year.']);
            }

            $created = StudentElection::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'status' => 'draft', 'created_by' => $u, 'ballot_method' => 'classroom_single_seat_v1', 'application_method' => 'versioned_v1']);

            app(StudentElectionCommercialAccess::class)->normal($s);

            if (! $created->exists) {
                throw new \RuntimeException('Election administrative persistence failed.');
            }
            app(StudentElectionLifecycleAudit::class)->record($s, (string) $created->id, $u, 'created', 'student_elections', (string) $created->id);

            return $created;
        });
    }

    public function position(string $s, array $d, string $u): StudentLeadershipPosition
    {
        return DB::transaction(function () use ($s, $d, $u) {
            // Serialize duplicate checks before taking commercial-row locks.
            DB::table('schools')->where('id', $s)->lockForUpdate()->firstOrFail();
            app(StudentElectionCommercialAccess::class)->normal($s);
            $d['code'] = isset($d['code']) && trim($d['code']) !== '' ? trim($d['code']) : null;
            foreach (['grade_id', 'stream_id', 'minimum_grade_id', 'maximum_grade_id'] as $f) {
                if (! empty($d[$f])) {
                    $table = $f === 'stream_id' ? 'streams' : 'grades';
                    if (! DB::table($table)->where('id', $d[$f])->where('school_id', $s)->exists()) {
                        throw ValidationException::withMessages([$f => 'Scope does not belong to this school.']);
                    }
                }
            }if (StudentLeadershipPosition::current()->where('school_id', $s)->where('scope', $d['scope'])->where(function ($q) use ($d) {
                $q->where('name', $d['name']);
                if ($d['code'] !== null) {
                    $q->orWhere('code', $d['code']);
                }
            })->exists()) {
                throw ValidationException::withMessages(['name' => 'Position already exists in this scope.']);
            }

            $created = StudentLeadershipPosition::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'created_by' => $u]);

            app(StudentElectionCommercialAccess::class)->normal($s);

            if (! $created->exists) {
                throw new \RuntimeException('Election administrative persistence failed.');
            }
            app(StudentElectionLifecycleAudit::class)->record($s, null, $u, 'position_created', 'student_leadership_positions', (string) $created->id);

            return $created;
        });
    }

    public function attach(string $s, string $e, string $p, array $d = [], ?string $u = null): StudentElectionPosition
    {
        return DB::transaction(function () use ($s, $e, $p, $d, $u) {
            $election = StudentElection::whereKey($e)->where('school_id', $s)->where('status', 'draft')->lockForUpdate()->firstOrFail();
            app(StudentElectionCommercialAccess::class)->normal($s);
            $position = StudentLeadershipPosition::current()->whereKey($p)->where('school_id', $s)->firstOrFail();
            if (StudentElectionPosition::where('election_id', $e)->where('position_id', $p)->exists()) {
                throw ValidationException::withMessages(['position' => 'Position is already attached.']);
            }

            $created = StudentElectionPosition::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'election_id' => $election->id, 'position_id' => $position->id]);

            app(StudentElectionCommercialAccess::class)->normal($s);

            if (! $created->exists) {
                throw new \RuntimeException('Election administrative persistence failed.');
            }
            app(StudentElectionLifecycleAudit::class)->record($s, $e, $u ?? auth()->id(), 'position_attached', 'student_election_positions', (string) $created->id, ['position_id' => $p]);

            return $created;
        });
    }

    public function generateVoters(string $s, string $e, ?string $u = null): int
    {
        return DB::transaction(function () use ($s, $e, $u) {
            $election = StudentElection::current()->whereKey($e)->where('school_id', $s)->whereIn('status', ['draft', 'nominations_open', 'nominations_closed', 'campaigning'])->lockForUpdate()->firstOrFail();
            app(StudentElectionCommercialAccess::class)->normal($s);
            $count = 0;
            foreach ($election->electionPositions()->where('school_id', $s)->where('active', true)
                ->whereHas('position', fn ($q) => $q->where('school_id', $s)->where('is_deleted', false))
                ->with('position')->get() as $ep) {
                $q = DB::table('learners')->where('school_id', $s)->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
                if ($ep->position->grade_id) {
                    $q->where('grade_id', $ep->position->grade_id);
                }
                if ($ep->position->stream_id) {
                    $q->where('stream_id', $ep->position->stream_id);
                }
                // Bound memory and SQL bindings; keep existing eligibility/participation unchanged.
                $q->select('id')->chunkById(500, function ($learners) use ($s, $e, $ep, &$count): void {
                    $rows = [];
                    $at = now();
                    foreach ($learners as $learner) {
                        $rows[] = ['id' => (string) Str::uuid(), 'school_id' => $s, 'election_id' => $e,
                            'election_position_id' => $ep->id, 'learner_id' => $learner->id,
                            'eligible' => true, 'has_voted' => false, 'created_at' => $at, 'updated_at' => $at];
                    }
                    $count += DB::table('student_election_voters')->insertOrIgnore($rows);
                });
            }
            app(StudentElectionCommercialAccess::class)->normal($s);
            app(StudentElectionLifecycleAudit::class)->record($s, $e, $u ?? auth()->id(), 'voters_generated', 'student_elections', $e, ['inserted' => $count]);

            return $count;
        });
    }

    public function transition(string $s, string $e, string $to, string $u): StudentElection
    {
        return DB::transaction(function () use ($s, $e, $to, $u) {
            $allowed = ['draft' => ['nominations_open', 'cancelled'], 'nominations_open' => ['nominations_closed', 'cancelled'], 'nominations_closed' => ['campaigning', 'voting_open', 'cancelled'], 'campaigning' => ['voting_open', 'cancelled'], 'voting_open' => ['voting_closed']];
            $x = StudentElection::current()->whereKey($e)->where('school_id', $s)->lockForUpdate()->firstOrFail();
            if (! in_array($to, $allowed[$x->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid election lifecycle transition.']);
            }
            if ($to === 'cancelled') {
                if ($x->integrity_hold) {
                    throw ValidationException::withMessages(['election' => 'Integrity review is required.']);
                }
            } elseif ($to === 'voting_closed') {
                if ($x->integrity_hold) {
                    throw ValidationException::withMessages(['election' => 'Integrity review is required.']);
                }
                $closing = clone $x;
                $closing->status = 'voting_closed';
                app(StudentElectionCommercialAccess::class)->available($closing);
            } else {
                app(StudentElectionApplicationService::class)->assertNotHeld($x);
            }
            if ($to === 'voting_open') {
                app(StudentElectionApplicationService::class)->assertReady($x);
            }
            $checkedAt = now();
            if ($to === 'voting_open' && ($checkedAt->lt($x->voting_opens_at) || $checkedAt->gte($x->voting_closes_at))) {
                throw ValidationException::withMessages(['status' => 'Voting window is not active.']);
            }
            if ($to === 'voting_open') {
                if ($x->tie_break_rule !== 'manual_runoff') {
                    throw ValidationException::withMessages(['tie_break_rule' => 'Only the agreed runoff rule is supported.']);
                }
                $positions = $x->electionPositions()->where('active', true)->with('position')->get();
                if ($positions->isEmpty()) {
                    throw ValidationException::withMessages(['positions' => 'Active election positions are required.']);
                }
                foreach ($positions as $ep) {
                    if (! $ep->position || (string) $ep->school_id !== $s || (string) $ep->position->school_id !== $s) {
                        throw ValidationException::withMessages(['positions' => 'Election position scope is inconsistent.']);
                    }
                    if ((int) ($ep->number_of_winners ?? $ep->position->number_of_winners) !== 1) {
                        throw ValidationException::withMessages(['number_of_winners' => 'Multi-seat voting policy has not been approved.']);
                    }
                }
            }
            if ($to === 'voting_open') {
                if (! in_array($x->ballot_method, ['legacy_single_selection', 'classroom_single_seat_v1'], true)) {
                    throw ValidationException::withMessages(['ballot_method' => 'Unsupported counting method.']);
                }
                if ($x->ballot_method === 'classroom_single_seat_v1') {
                    $tz = app(StudentElectionClassroomAccess::class)->timezone($x);
                    if ($x->voting_opens_at->copy()->setTimezone($tz)->toDateString() !== $x->voting_closes_at->copy()->subSecond()->setTimezone($tz)->toDateString()) {
                        throw ValidationException::withMessages(['window' => 'Classroom voting must fit one school-local day.']);
                    }
                    $x->setAttribute('ballot_timezone', $tz);
                }
                app(StudentElectionRoundService::class)->freeze($x);
                app(StudentElectionCommercialAccess::class)->opened($x, $u);
            }
            $from = (string) $x->status;
            if (! $x->update(['status' => $to])) {
                throw new \RuntimeException('Election transition persistence failed.');
            }

            app(StudentElectionLifecycleAudit::class)->record($s, $e, $u, 'transitioned', 'student_elections', $e, ['from' => $from, 'to' => $to]);

            return $x;
        });
    }

    public function review(string $s, string $c, string $status, string $u, ?string $comments = null): StudentElectionCandidate
    {
        if (! in_array($status, ['approved', 'rejected', 'disqualified'], true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported candidate review status.']);
        }

        return DB::transaction(function () use ($s, $c, $status, $u, $comments) {
            $candidate = StudentElectionCandidate::whereKey($c)->where('school_id', $s)
                ->where('is_deleted', false)->firstOrFail();
            $ep = StudentElectionPosition::whereKey($candidate->election_position_id)
                ->where('school_id', $s)->firstOrFail();
            $election = StudentElection::current()->whereKey($ep->election_id)
                ->where('school_id', $s)->lockForUpdate()->firstOrFail();
            app(StudentElectionCommercialAccess::class)->normal($s);
            if ($election->application_method === 'versioned_v1') {
                throw ValidationException::withMessages(['application' => 'Versioned elections require exact-version application board review.']);
            }
            if (! in_array($election->status, ['draft', 'nominations_open', 'nominations_closed', 'campaigning'], true)) {
                throw ValidationException::withMessages(['candidate' => 'Candidate review is frozen for this election phase.']);
            }
            $candidate = StudentElectionCandidate::whereKey($c)->where('school_id', $s)
                ->where('election_position_id', $ep->id)->where('is_deleted', false)->lockForUpdate()->firstOrFail();
            if (! $candidate->update(['status' => $status, 'review_comments' => $comments,
                'reviewed_by' => $u, 'reviewed_at' => now()])) {
                throw new \RuntimeException('Candidate review persistence failed.');
            }

            app(StudentElectionCommercialAccess::class)->normal($s);

            app(StudentElectionLifecycleAudit::class)->record($s, (string) $election->id, $u, 'legacy_candidate_reviewed', 'student_election_candidates', $c, ['status' => $status]);

            return $candidate;
        });
    }

    public function tally(string $s, string $e, string $u)
    {
        return app(StudentElectionResultService::class)->tally($s, $e, $u);
    }

    public function publish(string $s, string $e, string $u)
    {
        return app(StudentElectionResultService::class)->publish($s, $e, $u);
    }
}
