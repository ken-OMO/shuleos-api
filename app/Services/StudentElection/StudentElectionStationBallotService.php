<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentElectionStationBallotService
{
    public function __construct(private readonly StudentElectionClassroomAccess $access) {}

    private function candidates(string $school, string $ep, ?array $only = null): array
    {
        $query = DB::table('student_election_candidates as c')->join('learners as l', 'l.id', '=', 'c.learner_id')
            ->where('c.school_id', $school)->where('l.school_id', $school)->where('c.election_position_id', $ep)
            ->where('c.status', 'approved')->where('c.is_deleted', false)->where('l.active', true)->where('l.is_deleted', false);
        if ($only !== null) {
            $query->whereIn('c.id', $only);
        }

        return $query->orderBy('c.id')->get(['c.id', 'l.first_name', 'l.middle_name', 'l.last_name'])
            ->map(fn ($c) => ['id' => $c->id, 'display_name' => trim(implode(' ', array_filter([$c->first_name, $c->middle_name, $c->last_name])))])->all();
    }

    private function originalElectorate(StudentElection $e, string $ep, string $learner): void
    {
        $f = DB::table('student_election_electorates')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('election_position_id', $ep)->first();
        if (! $f || $f->ballot_method !== $e->ballot_method) {
            $this->access->fail('electorate', 'Pre-opening eligibility evidence is required.');
        }
        $ids = json_decode($f->learner_ids, true, 512, JSON_THROW_ON_ERROR);
        $current = DB::table('student_election_voters')->where('school_id', $e->school_id)->where('election_id', $e->id)
            ->where('election_position_id', $ep)->where('eligible', true)->orderBy('learner_id')->pluck('learner_id')->all();
        if ($current !== $ids || ! hash_equals($f->digest, StudentElectionOutcomePolicy::digest($ids)) || ! in_array($learner, $ids, true)) {
            $this->access->fail('electorate', 'Original eligibility membership is inconsistent.');
        }
    }

    public function definition(StudentElection $e, object $learner, bool $lockRegistry = false): array
    {
        $slots = [];
        if ($e->status === 'voting_open') {
            if ($e->ballot_method !== 'classroom_single_seat_v1') {
                $this->access->fail('election', 'An already opened legacy poll cannot be converted into a classroom election.');
            }
            $registry = DB::table('student_election_voters')->where('school_id', $e->school_id)->where('election_id', $e->id)
                ->where('learner_id', $learner->id)->where('eligible', true)->orderBy('election_position_id');
            $registry = ($lockRegistry ? $registry->lockForUpdate() : $registry)->get();
            foreach ($registry as $v) {
                $this->originalElectorate($e, $v->election_position_id, $learner->id);
                $ep = DB::table('student_election_positions')->where('id', $v->election_position_id)->where('school_id', $e->school_id)->where('election_id', $e->id)->where('active', true)->first();
                $p = $ep ? DB::table('student_leadership_positions')->where('id', $ep->position_id)->where('school_id', $e->school_id)->where('active', true)->where('is_deleted', false)->first() : null;
                if (! $p || (int) ($ep->number_of_winners ?? $p->number_of_winners) !== 1
                    || $p->minimum_grade_id !== null || $p->maximum_grade_id !== null
                    || ($p->grade_id !== null && $p->grade_id !== $learner->grade_id) || ($p->stream_id !== null && $p->stream_id !== $learner->stream_id)
                    || ! in_array($p->scope, ['school', 'grade', 'stream'], true)
                    || ($p->scope === 'grade' && $p->grade_id !== $learner->grade_id) || ($p->scope === 'stream' && $p->stream_id !== $learner->stream_id)
                    || ! in_array($ep->voter_eligibility_rules, [null, '[]', '{}'], true)) {
                    $this->access->fail('positions', 'Position scope or voting policy needs resolution.');
                }
                $candidates = $this->candidates($e->school_id, $ep->id);
                if (count($candidates) !== DB::table('student_election_candidates')->where('school_id', $e->school_id)->where('election_position_id', $ep->id)->where('status', 'approved')->where('is_deleted', false)->count()) {
                    $this->access->fail('positions', 'An approved candidate profile is unavailable.');
                }
                if ($candidates === []) {
                    $this->access->fail('positions', 'Every eligible office must have an approved candidate.');
                }
                $slots[] = ['slot_id' => $ep->id, 'election_position_id' => $ep->id, 'round_id' => null, 'registry_id' => $v->id,
                    'kind' => count($candidates) === 1 ? 'confirmation' : 'plurality', 'name' => $p->name, 'candidates' => $candidates,
                    'has_voted' => (bool) $v->has_voted, 'opens_at' => $e->voting_opens_at->toISOString(), 'closes_at' => $e->voting_closes_at->toISOString()];
            }
        } elseif ($e->status === 'tallied') {
            $snapshot = app(StudentElectionResultService::class)->verifiedStationSnapshot($e);
            $resolved = app(StudentElectionRoundService::class)->resolve($snapshot);
            foreach ($resolved->payload['outcome']['positions'] as $p) {
                if (! $p['active'] || count(array_filter($p['outcome']['rows'], fn ($r) => $r['result_status'] === 'winner')) === 1) {
                    continue;
                }
                $eligible = DB::table('student_election_voters')->where('school_id', $e->school_id)->where('election_id', $e->id)
                    ->where('election_position_id', $p['election_position_id'])->where('learner_id', $learner->id)->where('eligible', true)->exists();
                if (! $eligible) {
                    continue;
                }
                $round = DB::table('student_election_rounds')->where('school_id', $e->school_id)->where('election_id', $e->id)
                    ->where('election_position_id', $p['election_position_id'])->orderByDesc('sequence')->first();
                if (! $round || $round->status !== 'open') {
                    $this->access->fail('round', 'Every unresolved eligible office must have its current round open.');
                }
                app(StudentElectionCommercialAccess::class)->live($e, $round);
                $d = json_decode($round->definition, true, 512, JSON_THROW_ON_ERROR);
                $voters = DB::table('student_election_round_voters')->where('round_id', $round->id)->where('learner_id', $learner->id);
                $v = ($lockRegistry ? $voters->lockForUpdate() : $voters)->firstOrFail();
                $slots[] = ['slot_id' => $p['election_position_id'], 'election_position_id' => $p['election_position_id'], 'round_id' => $round->id,
                    'registry_id' => $v->id, 'kind' => $round->kind === 'confirmation' ? 'confirmation' : 'plurality', 'name' => $p['name'],
                    'candidates' => $this->candidates($e->school_id, $p['election_position_id'], array_column($d['candidates'], 'candidate_id')),
                    'has_voted' => (bool) $v->has_voted, 'opens_at' => $d['opens_at'], 'closes_at' => $d['closes_at']];
            }
        } else {
            $this->access->fail('election', 'Voting is not open.');
        }
        if (count($slots) > 100) {
            $this->access->fail('positions', 'At most 100 positions are supported per complete ballot.');
        }
        if ($slots === [] || count(array_filter($slots, fn ($p) => $p['has_voted'])) > 0) {
            $this->access->fail('vote', 'No complete unused ballot is available; partial legacy participation requires review.');
        }
        usort($slots, fn ($a, $b) => strcmp($a['slot_id'], $b['slot_id']));
        foreach ($slots as &$slot) {
            unset($slot['has_voted']);
        }
        unset($slot);

        return ['method' => 'complete_single_seat_v1', 'timezone' => $this->access->timezone($e), 'election_id' => (string) $e->id, 'slots' => $slots];
    }

    public function assertWindow(array $definition): void
    {
        $now = now();
        foreach ($definition['slots'] as $slot) {
            if ($now->lt(CarbonImmutable::parse($slot['opens_at'])) || $now->gte(CarbonImmutable::parse($slot['closes_at']))) {
                $this->access->fail('window', 'Voting window is not active.');
            }
        }
    }

    public function publicDefinition(array $definition): array
    {
        foreach ($definition['slots'] as &$slot) {
            unset($slot['registry_id']);
        }
        unset($slot);

        return $definition;
    }

    public function write(StudentElection $e, array $definition, array $answers): void
    {
        try {
            $accepted = app(StudentElectionCompleteBallotPolicy::class)->validate($definition['slots'], $answers);
        } catch (\InvalidArgumentException $error) {
            $this->access->fail('ballots', $error->getMessage());
        }
        foreach ($definition['slots'] as $slot) {
            $round = $slot['round_id'] === null ? null : DB::table('student_election_rounds')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $slot['round_id'])->firstOrFail();
            app(StudentElectionCommercialAccess::class)->live($e, $round);
        }
        // Randomize position insertion order; there is no shared ballot batch,
        // session/device reference or named participation ID in any ballot.
        $slots = $definition['slots'];
        shuffle($slots);
        foreach ($slots as $slot) {
            $answer = $accepted[$slot['slot_id']];
            if ($slot['round_id'] !== null) {
                $table = 'student_election_round_ballots';
                $ballot = ['id' => (string) Str::uuid(), 'round_id' => $slot['round_id'], 'candidate_id' => $answer['candidate_id'],
                    'choice' => $answer['choice'], 'random_marker' => hash('sha256', random_bytes(32))];
                $registry = 'student_election_round_voters';
                $participation = ['has_voted' => true, 'approval_hash' => null, 'approved_by' => null, 'attendance_id' => null, 'approval_expires_at' => null];
            } else {
                $table = 'student_election_ballots';
                $ballot = ['id' => (string) Str::uuid(), 'school_id' => $e->school_id, 'election_id' => $e->id,
                    'election_position_id' => $slot['election_position_id'], 'candidate_id' => $answer['candidate_id'], 'choice' => $answer['choice'],
                    'anonymous_ballot_token_hash' => hash('sha256', random_bytes(32)), 'ballot_status' => 'valid'];
                $registry = 'student_election_voters';
                $participation = ['has_voted' => true];
            }
            if (! DB::table($table)->insert($ballot) || DB::table($registry)->where('id', $slot['registry_id'])->where('has_voted', false)->update($participation) !== 1) {
                throw new \RuntimeException('Atomic complete ballot persistence failed.');
            }
        }
    }
}
