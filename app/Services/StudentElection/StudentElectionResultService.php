<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\Learner;
use App\Models\StudentElection;
use App\Models\StudentElectionCandidate;
use App\Models\StudentElectionDeclaration;
use App\Models\StudentElectionResult;
use App\Models\StudentElectionTallySnapshot;
use App\Models\StudentLeadershipPosition;
use App\Models\StudentLeadershipTerm;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionResultService
{
    public function __construct(private readonly AuthContextService $access, private readonly StudentElectionOutcomePolicy $policy) {}

    private function actor(string $school, string $id, string $permission): User
    {
        $actor = User::withoutGlobalScopes()->where('school_id', $school)->findOrFail($id);
        $this->access->assertAccessible($actor);
        abort_unless($this->access->hasPermission($actor, 'manage_student_elections')
            && $this->access->hasPermission($actor, $permission), 403);

        return $actor;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function reconcile(User $actor, StudentElection $election): void
    {
        if (! app(StudentElectionReconciliationService::class)->inspect($actor, $election->id)['reconciled']) {
            $this->reject('reconciliation', 'Election counts or supported counting rules require resolution.');
        }
    }

    private function outcome(StudentElection $election): array
    {
        if (! in_array($election->ballot_method, ['legacy_single_selection', 'classroom_single_seat_v1'], true)) {
            $this->reject('ballot_method', 'Unsupported election counting method.');
        }
        $school = (string) $election->school_id;
        $timezone = $election->ballot_method === 'classroom_single_seat_v1' ? $election->ballot_timezone
            : (DB::table('schools')->where('id', $school)->value('timezone') ?: config('app.timezone'));
        if (! is_string($timezone) || $timezone === '') {
            $this->reject('configuration', 'Frozen classroom timezone is required.');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            $this->reject('configuration', 'School timezone is invalid.');
        }
        if ($election->tie_break_rule !== 'manual_runoff') {
            $this->reject('tie_break_rule', 'Only the agreed runoff rule is supported.');
        }
        if (! DB::table('academic_years')->where('id', $election->academic_year_id)->where('school_id', $school)->exists()
            || ($election->term_id !== null && ! DB::table('terms')->where('id', $election->term_id)
                ->where('academic_year_id', $election->academic_year_id)->where('school_id', $school)->exists())) {
            $this->reject('configuration', 'Election academic scope is inconsistent.');
        }
        $positions = [];
        $active = 0;
        $approvedLearners = [];
        foreach ($election->electionPositions()->with('position')->orderBy('id')->get() as $ep) {
            $position = $ep->position;
            if ((string) $ep->school_id !== $school || ! $position
                || (string) $position->school_id !== $school || $position->is_deleted) {
                $this->reject('configuration', 'Election position ownership is inconsistent.');
            }
            $seats = (int) ($ep->number_of_winners ?? $position->number_of_winners);
            if ($seats < 1) {
                $this->reject('seats', 'A positive configured seat count is required.');
            }
            $counts = DB::table('student_election_ballots')->where('school_id', $school)
                ->where('election_id', $election->id)->where('election_position_id', $ep->id)
                ->where('ballot_status', 'valid')->where('choice', 'selection')->selectRaw('candidate_id, COUNT(*) AS total')
                ->groupBy('candidate_id')->pluck('total', 'candidate_id');
            $candidates = [];
            foreach (StudentElectionCandidate::withoutGlobalScopes()->where('election_position_id', $ep->id)
                ->where('status', 'approved')->where('is_deleted', false)->orderBy('id')->get() as $candidate) {
                if ((string) $candidate->school_id !== $school
                    || ! Learner::withoutGlobalScopes()->whereKey($candidate->learner_id)->where('school_id', $school)
                        ->where('active', true)->where('is_deleted', false)->exists()) {
                    $this->reject('configuration', 'Approved candidate identity is inconsistent.');
                }
                $candidates[] = ['candidate_id' => (string) $candidate->id,
                    'learner_id' => (string) $candidate->learner_id,
                    'vote_count' => (int) ($counts[$candidate->id] ?? 0)];
            }
            $enabled = (bool) $ep->active && (bool) $position->active;
            if ($enabled) {
                $active++;
                foreach ($candidates as $candidate) {
                    if (isset($approvedLearners[$candidate['learner_id']])) {
                        $this->reject('configuration', 'A learner has multiple approved election applications.');
                    }
                    $approvedLearners[$candidate['learner_id']] = true;
                }
            } elseif (DB::table('student_election_ballots')->where('election_id', $election->id)->where('election_position_id', $ep->id)->exists()) {
                $this->reject('configuration', 'An inactive position contains accepted selections.');
            }
            $positionOutcome = $enabled ? $this->policy->evaluate($candidates, $seats)
                : ['seats' => $seats, 'rows' => [], 'blocked_reasons' => []];
            if ($enabled && $election->ballot_method === 'classroom_single_seat_v1' && count($candidates) === 1) {
                $totals = DB::table('student_election_ballots')->where('school_id', $school)->where('election_id', $election->id)
                    ->where('election_position_id', $ep->id)->where('ballot_status', 'valid')->selectRaw('choice, COUNT(*) AS total')->groupBy('choice')->pluck('total', 'choice');
                $positionOutcome = app(StudentElectionRoundPolicy::class)->evaluate(['policy' => StudentElectionRoundPolicy::VERSION,
                    'seats' => $seats, 'kind' => 'confirmation', 'candidates' => $candidates],
                    ['approve' => (int) ($totals['approve'] ?? 0), 'reject' => (int) ($totals['reject'] ?? 0),
                        'abstain' => (int) ($totals['abstain'] ?? 0), 'selections' => [], 'participations' => array_sum($totals->all())]);
            }
            $positions[] = [
                'election_position_id' => (string) $ep->id, 'position_id' => (string) $position->id,
                'name' => $position->name, 'code' => $position->code, 'active' => $enabled,
                'scope' => $position->scope, 'grade_id' => $position->grade_id, 'stream_id' => $position->stream_id,
                'minimum_grade_id' => $position->minimum_grade_id, 'maximum_grade_id' => $position->maximum_grade_id,
                'gender_requirement' => $position->gender_requirement,
                'candidate_eligibility_rules' => $ep->candidate_eligibility_rules,
                'voter_eligibility_rules' => $ep->voter_eligibility_rules,
                'candidates' => $candidates,
                'outcome' => $positionOutcome,
            ];
        }
        if ($active === 0) {
            $this->reject('configuration', 'At least one active election position is required.');
        }

        return [...($election->ballot_method === 'classroom_single_seat_v1' ? ['ballot_method' => 'classroom_single_seat_v1', 'ballot_timezone' => $election->ballot_timezone] : []),
            'schema_version' => 1, 'school_id' => $school, 'election_id' => (string) $election->id,
            'title' => $election->title, 'timezone' => $timezone, 'academic_year_id' => (string) $election->academic_year_id,
            'term_id' => $election->term_id, 'tie_break_rule' => $election->tie_break_rule,
            'voting_opens_at' => $election->voting_opens_at->toISOString(),
            'voting_closes_at' => $election->voting_closes_at->toISOString(), 'positions' => $positions];
    }

    private function expectedRows(array $outcome): array
    {
        $rows = [];
        foreach ($outcome['positions'] as $position) {
            foreach ($position['outcome']['rows'] as $row) {
                $key = $position['election_position_id'].':'.$row['candidate_id'];
                $rows[$key] = ['school_id' => $outcome['school_id'], 'election_id' => $outcome['election_id'],
                    'election_position_id' => $position['election_position_id'], 'candidate_id' => $row['candidate_id'],
                    'vote_count' => $row['vote_count'], 'rank' => $row['rank'], 'result_status' => $row['result_status']];
            }
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    private function evidence(StudentElection $election): StudentElectionTallySnapshot
    {
        $snapshot = StudentElectionTallySnapshot::where('election_id', $election->id)
            ->where('school_id', $election->school_id)->first();
        $payload = $snapshot?->getAttribute('payload');
        if (! $snapshot || ! is_array($payload)
            || ! is_array($payload['outcome'] ?? null) || ! is_array($payload['result_ids'] ?? null)
            || ! hash_equals($snapshot->digest, StudentElectionOutcomePolicy::digest($payload))) {
            $this->reject('results', 'Verified tally evidence is missing or inconsistent.');
        }
        if (! hash_equals(StudentElectionOutcomePolicy::digest($payload['outcome']), StudentElectionOutcomePolicy::digest($this->outcome($election)))) {
            $this->reject('results', 'Candidate identities, configuration or accepted totals changed after tally.');
        }
        $expected = $this->expectedRows($payload['outcome']);
        if (array_keys($expected) !== array_keys($payload['result_ids'])) {
            // JSONB key order is not meaningful.
            $a = array_keys($expected);
            $b = array_keys($payload['result_ids']);
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);
            if ($a !== $b) {
                $this->reject('results', 'Tally result membership is inconsistent.');
            }
        }
        $actual = [];
        $positions = array_column($payload['outcome']['positions'], 'election_position_id');
        foreach (StudentElectionResult::withoutGlobalScopes()->where(fn ($q) => $q
            ->where('election_id', $election->id)->orWhereIn('election_position_id', $positions))->get() as $result) {
            $key = $result->election_position_id.':'.$result->candidate_id;
            if (isset($actual[$key]) || ! isset($payload['result_ids'][$key])
                || (string) $result->id !== $payload['result_ids'][$key]) {
                $this->reject('results', 'Tally result rows were added, replaced or removed.');
            }
            $actual[$key] = ['school_id' => (string) $result->school_id, 'election_id' => (string) $result->election_id,
                'election_position_id' => (string) $result->election_position_id, 'candidate_id' => (string) $result->candidate_id,
                'vote_count' => (int) $result->vote_count, 'rank' => (int) $result->rank, 'result_status' => $result->result_status];
        }
        if (! hash_equals(StudentElectionOutcomePolicy::digest($expected), StudentElectionOutcomePolicy::digest($actual))) {
            $this->reject('results', 'Stored result totals, ranks or winner flags differ from verified evidence.');
        }

        return $snapshot;
    }

    public function verifiedStationSnapshot(StudentElection $election): StudentElectionTallySnapshot
    {
        if ($election->status !== 'tallied') {
            $this->reject('election', 'Resolution station requires a verified original tally.');
        }

        return $this->evidence($election);
    }

    public function verifiedForRounds(User $actor, StudentElection $election): StudentElectionTallySnapshot
    {
        $actor = $this->actor((string) $election->school_id, (string) $actor->id, 'tally_student_elections');
        $this->reconcile($actor, $election);

        return $this->evidence($election);
    }

    // Only call after the controller/read service has authorized this election.
    // Legacy rows without new evidence remain readable under the prior rules;
    // no round can be attached to such an election.
    public function effectiveRows(string $school, string $id)
    {
        return DB::transaction(function () use ($school, $id) {
            $e = StudentElection::current()->where('school_id', $school)->whereKey($id)->sharedLock()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $base = StudentElectionResult::where('school_id', $school)->where('election_id', $id)->get();
            if (! DB::table('student_election_rounds')->where('election_id', $id)->exists()) {
                return $base;
            }
            $effective = app(StudentElectionRoundService::class)->resolve($this->evidence($e));
            $rows = collect();
            foreach ($effective->payload['outcome']['positions'] as $position) {
                foreach ($position['outcome']['rows'] as $row) {
                    $original = $base->first(fn ($r) => $r->election_position_id === $position['election_position_id'] && $r->candidate_id === $row['candidate_id']);
                    if (! $original) {
                        $this->reject('results', 'Original candidate result evidence is missing.');
                    }
                    $display = clone $original;
                    $display->vote_count = $row['vote_count'];
                    $display->rank = $row['rank'];
                    $display->result_status = $row['result_status'];
                    $round = collect($effective->payload['round_chain'])->last(fn ($r) => $r['election_position_id'] === $position['election_position_id'] && $r['status'] === 'tallied');
                    $display->setAttribute('counting_stage', $round ? 'resolution_round' : 'original');
                    $display->setAttribute('resolution_round_id', $round['round_id'] ?? null);
                    $rows->push($display);
                }
            }

            foreach ($base as $original) {
                if (! $rows->contains(fn ($r) => $r->id === $original->id)) {
                    $display = clone $original;
                    $display->result_status = 'not_elected';
                    $display->setAttribute('counting_stage', 'original');
                    $display->setAttribute('resolution_round_id', null);
                    $rows->push($display);
                }
            }

            return $rows;
        });
    }

    private function gates(StudentElectionTallySnapshot $snapshot): array
    {
        $positions = [];
        $ready = true;
        foreach ($snapshot->payload['outcome']['positions'] as $position) {
            $outcome = $position['outcome'];
            $winners = count(array_filter($outcome['rows'], static fn ($r) => $r['result_status'] === 'winner'));
            $reasons = $outcome['blocked_reasons'];
            if ($position['active'] && $winners !== $outcome['seats']) {
                $reasons[] = 'seat_count_not_resolved';
            }
            if ($winners > $outcome['seats']) {
                $reasons[] = 'seat_limit_exceeded';
            }
            $occupied = StudentLeadershipTerm::withoutGlobalScopes()->where('school_id', $snapshot->school_id)
                ->where('position_id', $position['position_id'])->where('status', 'active')->where('is_deleted', false)
                ->where(fn ($q) => $q->whereNull('election_id')->orWhere('election_id', '<>', $snapshot->election_id))->count();
            if ($occupied + $winners > $outcome['seats']) {
                $reasons[] = 'active_seats_occupied';
            }
            $ready = $ready && $reasons === [];
            $positions[] = ['election_position_id' => $position['election_position_id'], 'name' => $position['name'],
                'seats' => $outcome['seats'], 'winners' => $winners, 'blocked_reasons' => array_values(array_unique($reasons))];
        }

        return ['snapshot_id' => $snapshot->id, 'ready' => $ready, 'positions' => $positions];
    }

    private function audit(StudentElection $election, User $actor, string $action, StudentElectionTallySnapshot $snapshot): void
    {
        if (! DB::table('student_election_audit_logs')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $election->school_id, 'election_id' => $election->id,
            'actor_user_id' => $actor->id, 'action' => $action, 'entity_type' => 'election', 'entity_id' => $election->id,
            'metadata' => json_encode(['snapshot_id' => $snapshot->id, 'digest' => $snapshot->digest], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ])) {
            throw new \RuntimeException('Election audit persistence failed.');
        }
    }

    public function tally(string $school, string $id, string $user)
    {
        $actor = $this->actor($school, $user, 'tally_student_elections');

        return DB::transaction(function () use ($actor, $school, $id) {
            $election = StudentElection::current()->where('school_id', $school)->whereKey($id)->lockForUpdate()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($election);
            $actor = $this->actor($school, (string) $actor->id, 'tally_student_elections');
            if (! in_array($election->status, ['voting_closed', 'tallied'], true)) {
                $this->reject('election', 'Voting must be formally closed before tallying.');
            }
            $this->reconcile($actor, $election);
            if ($election->status === 'tallied') {
                $this->evidence($election);

                return StudentElectionResult::where('school_id', $school)->where('election_id', $id)->get();
            }
            $positionIds = $election->electionPositions()->pluck('id');
            if (StudentElectionTallySnapshot::withoutGlobalScopes()->where('election_id', $id)->exists()
                || StudentElectionResult::withoutGlobalScopes()->where(fn ($q) => $q->where('election_id', $id)
                    ->orWhereIn('election_position_id', $positionIds))->exists()) {
                $this->reject('results', 'Existing outcome records require review; tally will not overwrite them.');
            }
            $outcome = $this->outcome($election);
            $rows = $this->expectedRows($outcome);
            $ids = [];
            foreach ($rows as $key => $row) {
                $ids[$key] = (string) Str::uuid();
                $result = StudentElectionResult::create(['id' => $ids[$key], ...$row, 'tallied_at' => now(), 'tallied_by' => $actor->id]);
                if (! $result->exists) {
                    throw new \RuntimeException('Tally result persistence failed.');
                }
            }
            $payload = ['outcome' => $outcome, 'result_ids' => $ids];
            $snapshot = StudentElectionTallySnapshot::create(['id' => (string) Str::uuid(),
                'school_id' => $school, 'election_id' => $id, 'payload' => $payload,
                'digest' => StudentElectionOutcomePolicy::digest($payload), 'created_by' => $actor->id, 'created_at' => now()]);
            if (! $snapshot->exists || ! $election->update(['status' => 'tallied'])) {
                throw new \RuntimeException('Tally evidence persistence failed.');
            }
            $this->audit($election, $actor, 'tallied', $snapshot);

            return StudentElectionResult::where('school_id', $school)->where('election_id', $id)->get();
        });
    }

    public function checks(User $user, string $id): array
    {
        abort_unless($user->getAttribute('school_id') !== null, 403);
        $actor = $this->actor((string) $user->school_id, (string) $user->id, 'publish_student_election_results');

        return DB::transaction(function () use ($actor, $id) {
            $election = StudentElection::current()->where('school_id', $actor->school_id)->whereKey($id)
                ->whereIn('status', ['tallied', 'published'])->sharedLock()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($election);
            $actor = $this->actor((string) $actor->school_id, (string) $actor->id, 'publish_student_election_results');
            $this->reconcile($actor, $election);
            $snapshot = app(StudentElectionRoundService::class)->resolve($this->evidence($election));
            if ($election->status === 'published') {
                $this->verifyDeclaration($election, $snapshot);
            }

            return ['status' => $election->status, ...$this->gates($snapshot)];
        });
    }

    private function verifyDeclaration(StudentElection $election, StudentElectionTallySnapshot $snapshot): void
    {
        $declaration = StudentElectionDeclaration::where('school_id', $election->school_id)->where('election_id', $election->id)->first();
        if (! $declaration || $declaration->tally_snapshot_id !== $snapshot->id || $declaration->digest !== $snapshot->digest) {
            $this->reject('declaration', 'Published declaration evidence is inconsistent.');
        }
        $hasRounds = ($snapshot->payload['round_chain'] ?? []) !== [];
        $resolutionDigest = StudentElectionOutcomePolicy::digest($snapshot->payload);
        if (($hasRounds || $declaration->resolution_digest !== null)
            && ! hash_equals((string) $declaration->resolution_digest, $resolutionDigest)) {
            $this->reject('declaration', 'Declared resolution chain changed.');
        }
        $expected = [];
        foreach ($snapshot->payload['outcome']['positions'] as $position) {
            foreach ($position['outcome']['rows'] as $row) {
                if ($row['result_status'] === 'winner') {
                    $expected[$position['position_id'].':'.$row['learner_id']] = true;
                }
            }
        }
        $terms = StudentLeadershipTerm::withoutGlobalScopes()->where('election_id', $election->id)->get();
        $ids = $terms->pluck('id')->sort()->values()->all();
        $declaredIds = $declaration->term_ids;
        sort($declaredIds, SORT_STRING);
        if ($ids !== $declaredIds || count($expected) !== $terms->count()) {
            $this->reject('declaration', 'Declared appointment membership changed; retry will not recreate it.');
        }
        foreach ($terms as $term) {
            $key = $term->position_id.':'.$term->learner_id;
            if (! isset($expected[$key]) || (string) $term->school_id !== (string) $election->school_id
                || $term->appointment_type !== 'elected' || $term->status !== 'active' || $term->is_deleted
                || (string) $term->academic_year_id !== (string) $election->academic_year_id
                || $term->term_id !== $election->term_id || (string) $term->starts_at !== (string) $declaration->starts_at) {
                $this->reject('declaration', 'Declared appointment identity changed.');
            }
            unset($expected[$key]);
        }
    }

    public function publish(string $school, string $id, string $user): StudentElection
    {
        $actor = $this->actor($school, $user, 'publish_student_election_results');

        return DB::transaction(function () use ($school, $id, $actor) {
            $election = StudentElection::current()->where('school_id', $school)->whereKey($id)->lockForUpdate()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($election);
            $actor = $this->actor($school, (string) $actor->id, 'publish_student_election_results');
            if (! in_array($election->status, ['tallied', 'published'], true)) {
                $this->reject('election', 'A verified tally is required before declaration.');
            }
            $this->reconcile($actor, $election);
            // Different elections may reference the same leadership position.
            // Lock those positions in stable order before checking occupancy.
            StudentLeadershipPosition::withoutGlobalScopes()->where('school_id', $school)
                ->whereIn('id', $election->electionPositions()->pluck('position_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $snapshot = app(StudentElectionRoundService::class)->resolve($this->evidence($election));
            if (! $this->gates($snapshot)['ready']) {
                $this->reject('declaration', 'Every position must have resolved outcomes within its seat limit.');
            }
            if ($election->status === 'published') {
                $this->verifyDeclaration($election, $snapshot);

                return $election;
            }
            if (StudentElectionDeclaration::withoutGlobalScopes()->where('election_id', $id)->exists()
                || StudentLeadershipTerm::withoutGlobalScopes()->where('election_id', $id)->exists()) {
                $this->reject('declaration', 'Existing appointment evidence requires review.');
            }
            $terms = [];
            $startsAt = now($snapshot->payload['outcome']['timezone'])->toDateString();
            foreach ($snapshot->payload['outcome']['positions'] as $position) {
                $winnerCount = count(array_filter($position['outcome']['rows'], static fn ($r) => $r['result_status'] === 'winner'));
                $occupied = StudentLeadershipTerm::withoutGlobalScopes()->where('school_id', $school)
                    ->where('position_id', $position['position_id'])->where('status', 'active')->where('is_deleted', false)->count();
                if ($occupied + $winnerCount > $position['outcome']['seats']) {
                    $this->reject('declaration', 'Existing active leadership terms leave insufficient seats.');
                }
                foreach ($position['outcome']['rows'] as $row) {
                    if ($row['result_status'] !== 'winner') {
                        continue;
                    }
                    $term = StudentLeadershipTerm::create(['id' => (string) Str::uuid(),
                        'school_id' => $school, 'election_id' => $id, 'position_id' => $position['position_id'],
                        'learner_id' => $row['learner_id'], 'academic_year_id' => $election->academic_year_id,
                        'term_id' => $election->term_id, 'starts_at' => $startsAt, 'status' => 'active', 'appointment_type' => 'elected']);
                    if (! $term->exists) {
                        throw new \RuntimeException('Appointment persistence failed.');
                    }
                    $terms[] = (string) $term->id;
                }
            }
            $declaration = StudentElectionDeclaration::create(['id' => (string) Str::uuid(),
                'school_id' => $school, 'election_id' => $id, 'tally_snapshot_id' => $snapshot->id,
                'digest' => $snapshot->digest, 'resolution_digest' => StudentElectionOutcomePolicy::digest($snapshot->payload),
                'term_ids' => $terms, 'starts_at' => $startsAt,
                'created_by' => $actor->id, 'created_at' => now()]);
            if (! $declaration->exists || ! $election->update(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()])) {
                throw new \RuntimeException('Declaration persistence failed.');
            }
            $this->audit($election, $actor, 'declared', $snapshot);

            return $election;
        });
    }
}
