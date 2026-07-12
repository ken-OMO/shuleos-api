<?php

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionPosition;
use App\Models\StudentElectionResult;
use App\Models\StudentLeadershipPosition;
use App\Models\StudentLeadershipTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionService
{
    public function create(string $s, array $d, string $u): StudentElection
    {
        return StudentElection::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'status' => 'draft', 'created_by' => $u]);
    }

    public function position(string $s, array $d, string $u): StudentLeadershipPosition
    {
        foreach (['grade_id', 'stream_id', 'minimum_grade_id', 'maximum_grade_id'] as $f) {
            if (! empty($d[$f])) {
                $table = $f === 'stream_id' ? 'streams' : 'grades';
                if (! DB::table($table)->where('id', $d[$f])->where('school_id', $s)->exists()) {
                    throw ValidationException::withMessages([$f => 'Scope does not belong to this school.']);
                }
            }
        }if (StudentLeadershipPosition::current()->where('school_id', $s)->where('scope', $d['scope'])->where(fn ($q) => $q->where('name', $d['name'])->orWhere('code', $d['code'] ?? null))->exists()) {
            throw ValidationException::withMessages(['name' => 'Position already exists in this scope.']);
        }

        return StudentLeadershipPosition::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'created_by' => $u]);
    }

    public function attach(string $s, string $e, string $p, array $d = []): StudentElectionPosition
    {
        $election = StudentElection::whereKey($e)->where('school_id', $s)->where('status', 'draft')->firstOrFail();
        $position = StudentLeadershipPosition::current()->whereKey($p)->where('school_id', $s)->firstOrFail();
        if (StudentElectionPosition::where('election_id', $e)->where('position_id', $p)->exists()) {
            throw ValidationException::withMessages(['position' => 'Position is already attached.']);
        }

        return StudentElectionPosition::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'election_id' => $election->id, 'position_id' => $position->id]);
    }

    public function generateVoters(string $s, string $e): int
    {
        return DB::transaction(function () use ($s, $e) {
            $election = StudentElection::current()->whereKey($e)->where('school_id', $s)->whereIn('status', ['draft', 'nominations_open', 'nominations_closed', 'campaigning'])->firstOrFail();
            $count = 0;
            foreach ($election->electionPositions()->where('active', true)->with('position')->get() as $ep) {
                $q = DB::table('learners')->where('school_id', $s)->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
                if ($ep->position->grade_id) {
                    $q->where('grade_id', $ep->position->grade_id);
                }if ($ep->position->stream_id) {
                    $q->where('stream_id', $ep->position->stream_id);
                }foreach ($q->pluck('id') as $l) {
                    if (! DB::table('student_election_voters')->where('election_position_id', $ep->id)->where('learner_id', $l)->exists()) {
                        DB::table('student_election_voters')->insert(['id' => (string) Str::uuid(), 'school_id' => $s, 'election_id' => $e, 'election_position_id' => $ep->id, 'learner_id' => $l, 'eligible' => true, 'has_voted' => false, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $count++;
                }
            }

            return $count;
        });
    }

    public function transition(string $s, string $e, string $to, string $u): StudentElection
    {
        $allowed = ['draft' => ['nominations_open', 'cancelled'], 'nominations_open' => ['nominations_closed', 'cancelled'], 'nominations_closed' => ['campaigning', 'voting_open', 'cancelled'], 'campaigning' => ['voting_open', 'cancelled'], 'voting_open' => ['voting_closed'], 'voting_closed' => ['tallied'], 'tallied' => ['published']];
        $x = StudentElection::current()->whereKey($e)->where('school_id', $s)->lockForUpdate()->firstOrFail();
        if (! in_array($to, $allowed[$x->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid election lifecycle transition.']);
        }if ($to === 'voting_open' && (now()->lt($x->voting_opens_at) || now()->gt($x->voting_closes_at))) {
            throw ValidationException::withMessages(['status' => 'Voting window is not active.']);
        }$x->update(['status' => $to] + ($to === 'published' ? ['published_by' => $u, 'published_at' => now()] : []));

        return $x;
    }

    public function review(string $s, string $c, string $status, string $u, ?string $comments = null): StudentElectionCandidate
    {
        $x = StudentElectionCandidate::whereKey($c)->where('school_id', $s)->where('is_deleted', false)->firstOrFail();
        $x->update(['status' => $status, 'review_comments' => $comments, 'reviewed_by' => $u, 'reviewed_at' => now()]);

        return $x;
    }

    public function tally(string $s, string $e, string $u)
    {
        return DB::transaction(function () use ($s, $e, $u) {
            $election = StudentElection::current()->whereKey($e)->where('school_id', $s)->where('status', 'voting_closed')->lockForUpdate()->firstOrFail();
            foreach ($election->electionPositions()->with('position')->get() as $ep) {
                $counts = DB::table('student_election_ballots as b')->join('student_election_candidates as c', 'c.id', '=', 'b.candidate_id')->where('b.election_position_id', $ep->id)->where('b.ballot_status', 'valid')->where('c.status', 'approved')->selectRaw('b.candidate_id, COUNT(*) votes')->groupBy('b.candidate_id')->orderByDesc('votes')->get();
                $prev = null;
                $rank = 0;
                foreach ($counts as $i => $row) {
                    if ($prev !== $row->votes) {
                        $rank = $i + 1;
                    }$prev = $row->votes;
                    $winners = $ep->number_of_winners ?? $ep->position->number_of_winners;
                    $status = $rank <= $winners ? 'winner' : 'not_elected';
                    $result = StudentElectionResult::firstOrNew(['election_position_id' => $ep->id, 'candidate_id' => $row->candidate_id]);
                    if (! $result->exists) {
                        $result->id = (string) Str::uuid();
                    }
                    $result->fill(['school_id' => $s, 'election_id' => $e, 'vote_count' => $row->votes, 'rank' => $rank, 'result_status' => $status, 'tallied_at' => now(), 'tallied_by' => $u])->save();
                }
            }$election->update(['status' => 'tallied']);

            return StudentElectionResult::where('election_id', $e)->get();
        });
    }

    public function publish(string $s, string $e, string $u)
    {
        return DB::transaction(function () use ($s, $e, $u) {
            $election = $this->transition($s, $e, 'published', $u);
            foreach (StudentElectionResult::where('election_id', $e)->where('result_status', 'winner')->get() as $r) {
                $candidate = StudentElectionCandidate::find($r->candidate_id);
                $ep = StudentElectionPosition::find($r->election_position_id);
                StudentLeadershipTerm::firstOrCreate(['school_id' => $s, 'position_id' => $ep->position_id, 'learner_id' => $candidate->learner_id, 'election_id' => $e], ['id' => (string) Str::uuid(), 'academic_year_id' => $election->academic_year_id, 'term_id' => $election->term_id, 'starts_at' => today(), 'status' => 'active', 'appointment_type' => 'elected']);
            }

            return $election;
        });
    }
}
