<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\StudentElectionTallySnapshot;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionRoundService
{
    public function __construct(private readonly AuthContextService $access, private readonly StudentElectionRoundPolicy $policy) {}

    private function deny(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function actor(User $actor, string $permission): User
    {
        $fresh = User::withoutGlobalScopes()->where('school_id', $actor->school_id)->findOrFail($actor->id);
        $this->access->assertAccessible($fresh);
        abort_unless($fresh->getAttribute('school_id') !== null && $this->access->hasPermission($fresh, 'manage_student_elections')
            && $this->access->hasPermission($fresh, $permission), 403);

        return $fresh;
    }

    // Internal hook; caller holds the election UPDATE lock before first opening.
    // No retrospective freeze of an election that has already accepted voting.
    public function freeze(StudentElection $election): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Electorate freeze requires an opening transaction.');
        }
        $election = StudentElection::current()->where('school_id', $election->school_id)->whereKey($election->id)->lockForUpdate()->firstOrFail();
        if (! in_array($election->status, ['nominations_closed', 'campaigning'], true)) {
            $this->deny('electorate', 'Original eligibility can only be frozen before first opening.');
        }
        foreach ($election->electionPositions()->where('active', true)->orderBy('id')->get() as $ep) {
            $ids = DB::table('student_election_voters')->where('school_id', $election->school_id)
                ->where('election_id', $election->id)->where('election_position_id', $ep->id)->where('eligible', true)
                ->orderBy('learner_id')->pluck('learner_id')->all();
            $existing = DB::table('student_election_electorates')->where('election_position_id', $ep->id)->first();
            if ($existing) {
                $this->electorate($election, (string) $ep->id);

                continue;
            }
            $this->insert('student_election_electorates', ['id' => (string) Str::uuid(), 'school_id' => $election->school_id,
                'election_id' => $election->id, 'election_position_id' => $ep->id,
                'learner_ids' => json_encode($ids, JSON_THROW_ON_ERROR), 'ballot_method' => $election->ballot_method, 'digest' => StudentElectionOutcomePolicy::digest($ids)]);
        }
    }

    private function electorate(StudentElection $election, string $ep): object
    {
        $record = DB::table('student_election_electorates')->where('school_id', $election->school_id)
            ->where('election_id', $election->id)->where('election_position_id', $ep)->first();
        if (! $record || $record->ballot_method !== $election->ballot_method) {
            $this->deny('electorate', 'Original frozen electorate is missing; legacy voting cannot be reconstructed.');
        }
        $ids = json_decode($record->learner_ids, true, 512, JSON_THROW_ON_ERROR);
        $current = DB::table('student_election_voters')->where('school_id', $election->school_id)
            ->where('election_id', $election->id)->where('election_position_id', $ep)->where('eligible', true)
            ->orderBy('learner_id')->pluck('learner_id')->all();
        if (! is_array($ids) || ! array_is_list($ids) || count(array_unique($ids)) !== count($ids)
            || ! hash_equals($record->digest, StudentElectionOutcomePolicy::digest($ids)) || $current !== $ids
            || DB::table('learners')->where('school_id', $election->school_id)->whereIn('id', $ids)->count() !== count($ids)) {
            $this->deny('electorate', 'Original eligibility membership changed.');
        }
        $record->ids = $ids;

        return $record;
    }

    private function insert(string $table, array $data): void
    {
        if (! DB::table($table)->insert($data)) {
            throw new \RuntimeException('Election round persistence failed.');
        }
    }

    private function audit(StudentElection $election, User $actor, string $action, string $round, array $metadata = []): void
    {
        if ($action === 'round_scheduled') {
            app(StudentElectionCommercialAccess::class)->normal($election->school_id);
        }
        $this->insert('student_election_audit_logs', ['id' => (string) Str::uuid(), 'school_id' => $election->school_id,
            'election_id' => $election->id, 'actor_user_id' => $actor->id, 'action' => $action,
            'entity_type' => 'resolution_round', 'entity_id' => $round,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function round(StudentElection $election, string $id): object
    {
        return DB::table('student_election_rounds')->where('school_id', $election->school_id)
            ->where('election_id', $election->id)->where('id', $id)->firstOrFail();
    }

    private function definition(object $round): array
    {
        $d = json_decode($round->definition, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($round->definition_digest, StudentElectionOutcomePolicy::digest($d))
            || $d['round_id'] !== $round->id || $d['election_id'] !== $round->election_id
            || $d['election_position_id'] !== $round->election_position_id || $d['kind'] !== $round->kind
            || $d['parent_round_id'] !== $round->parent_round_id || $d['sequence'] !== (int) $round->sequence
            || $d['source_snapshot_id'] !== $round->source_snapshot_id || $d['school_id'] !== $round->school_id
            || $d['policy'] !== StudentElectionRoundPolicy::VERSION) {
            $this->deny('round', 'Frozen round definition is inconsistent.');
        }

        return $d;
    }

    private function aggregate(object $round, array $definition): array
    {
        $registry = DB::table('student_election_round_voters')->where('round_id', $round->id)->orderBy('learner_id')->get();
        if ($registry->pluck('learner_id')->all() !== $definition['electorate']) {
            $this->deny('reconciliation', 'Round registry membership differs from the original electorate.');
        }
        $p = $registry->where('has_voted', true)->count();
        $counts = ['participations' => $p, 'approve' => 0, 'reject' => 0, 'abstain' => 0, 'selections' => []];
        $ballots = 0;
        foreach (DB::table('student_election_round_ballots')->where('round_id', $round->id)
            ->selectRaw('choice, candidate_id, COUNT(*) AS total')->groupBy('choice', 'candidate_id')->get() as $row) {
            $n = (int) $row->total;
            $ballots += $n;
            if ($definition['kind'] === 'confirmation' && in_array($row->choice, ['approve', 'reject', 'abstain'], true) && $row->candidate_id === null) {
                $counts[$row->choice] += $n;
            } elseif ($definition['kind'] !== 'confirmation' && $row->choice === 'abstain' && $row->candidate_id === null) {
                $counts['abstain'] += $n;
            } elseif ($definition['kind'] !== 'confirmation' && $row->choice === 'selection'
                && in_array($row->candidate_id, array_column($definition['candidates'], 'candidate_id'), true)) {
                $counts['selections'][$row->candidate_id] = $n;
            } else {
                $this->deny('reconciliation', 'Unsupported round ballot shape or candidate.');
            }
        }
        if ($ballots !== $p) {
            $this->deny('reconciliation', 'Round participation and anonymous ballot counts differ.');
        }

        return $this->policy->evaluate($definition, $counts);
    }

    // Caller holds root SHARE/UPDATE lock. Original evidence is verified by the
    // result service first. No recursion back into result verification here.
    public function resolve(StudentElectionTallySnapshot $snapshot): StudentElectionTallySnapshot
    {
        $effective = clone $snapshot;
        $payload = $snapshot->payload;
        $payload['round_chain'] = [];
        $election = StudentElection::current()->where('school_id', $snapshot->school_id)->findOrFail($snapshot->election_id);
        $all = DB::table('student_election_rounds')->where('election_id', $election->id)->orderBy('sequence')->orderBy('id')->get();
        $seen = 0;
        foreach ($payload['outcome']['positions'] as &$position) {
            $parent = null;
            $sequence = 0;
            foreach ($all->where('election_position_id', $position['election_position_id']) as $round) {
                $seen++;
                if ($round->school_id !== $snapshot->school_id || $round->source_snapshot_id !== $snapshot->id
                    || $round->parent_round_id !== $parent || (int) $round->sequence !== ++$sequence || ! $position['active']) {
                    $this->deny('round', 'Round ancestry or ownership is inconsistent.');
                }
                $electorate = $this->electorate($election, $position['election_position_id']);
                $d = $this->definition($round);
                try {
                    $next = $this->policy->next($position['outcome']);
                } catch (\InvalidArgumentException) {
                    $this->deny('round', 'An already resolved or unsupported outcome was repolled.');
                }
                $candidateIds = array_column($d['candidates'], 'candidate_id');
                sort($candidateIds, SORT_STRING);
                $expectedCandidates = array_values(array_filter($position['candidates'], fn ($c) => in_array($c['candidate_id'], $next['candidate_ids'], true)));
                if ($d['electorate'] !== $electorate->ids || $d['electorate_digest'] !== $electorate->digest
                    || $d['kind'] !== $next['kind'] || $candidateIds !== $next['candidate_ids']
                    || StudentElectionOutcomePolicy::digest($d['candidates']) !== StudentElectionOutcomePolicy::digest($expectedCandidates)
                    || $d['source_digest'] !== $snapshot->digest) {
                    $this->deny('round', 'Frozen electorate or qualifying candidates differ from verified evidence.');
                }
                $entry = ['round_id' => $round->id, 'election_position_id' => $round->election_position_id, 'kind' => $round->kind, 'definition_digest' => $round->definition_digest, 'status' => $round->status,
                    'outcome_digest' => $round->outcome_digest];
                $payload['round_chain'][] = $entry;
                if ($round->status !== 'tallied') {
                    if ($round->outcome !== null || $round->outcome_digest !== null
                        || $all->where('parent_round_id', $round->id)->isNotEmpty()) {
                        $this->deny('round', 'Unfinished round has outcome evidence or descendants.');
                    }
                    $position['outcome']['blocked_reasons'][] = 'resolution_round_pending';
                } else {
                    $actual = $this->aggregate($round, $d);
                    $stored = json_decode($round->outcome, true, 512, JSON_THROW_ON_ERROR);
                    if (! hash_equals((string) $round->outcome_digest, StudentElectionOutcomePolicy::digest($stored))
                        || StudentElectionOutcomePolicy::digest($actual) !== StudentElectionOutcomePolicy::digest($stored)) {
                        $this->deny('round', 'Frozen round totals changed after tally.');
                    }
                    $position['outcome'] = $actual;
                }
                $parent = $round->id;
            }
        }
        unset($position);
        if ($seen !== $all->count()) {
            $this->deny('round', 'Unassigned resolution rounds exist.');
        }
        $effective->payload = $payload;

        return $effective;
    }

    public function create(User $user, string $electionId, string $ep, string $opens, string $closes): array
    {
        $user = $this->actor($user, 'manage_election_voters');

        return DB::transaction(function () use ($user, $electionId, $ep, $opens, $closes) {
            $election = StudentElection::current()->where('school_id', $user->school_id)->whereKey($electionId)->lockForUpdate()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($election);
            app(StudentElectionCommercialAccess::class)->normal($election->school_id);
            $user = $this->actor($user, 'manage_election_voters');
            if ($election->status !== 'tallied') {
                $this->deny('election', 'A closed, verified original tally is required.');
            }
            $snapshot = app(StudentElectionResultService::class)->verifiedForRounds($user, $election);
            $effective = $this->resolve($snapshot);
            $position = collect($effective->payload['outcome']['positions'])->firstWhere('election_position_id', $ep);
            if (! $position || ! $position['active']) {
                $this->deny('position', 'Active election position is unavailable.');
            }
            if (in_array('resolution_round_pending', $position['outcome']['blocked_reasons'], true)) {
                $this->deny('round', 'Finish the current round before scheduling another.');
            }
            try {
                $next = $this->policy->next($position['outcome']);
            } catch (\InvalidArgumentException $error) {
                $this->deny('round', $error->getMessage());
            }
            foreach ([$opens, $closes] as $instant) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $instant)) {
                    $this->deny('window', 'Explicit ISO-8601 offsets are required.');
                }
            }
            $timezone = $snapshot->payload['outcome']['timezone'];
            $start = CarbonImmutable::parse($opens)->utc();
            $end = CarbonImmutable::parse($closes)->utc();
            if ($start->lt(now()) || $end->lte($start) || $start->setTimezone($timezone)->toDateString() !== $end->subSecond()->setTimezone($timezone)->toDateString()) {
                $this->deny('window', 'A future round must fit within one school-local voting day.');
            }
            $electorate = $this->electorate($election, $ep);
            $previous = DB::table('student_election_rounds')->where('election_position_id', $ep)->orderByDesc('sequence')->first();
            $id = (string) Str::uuid();
            $d = ['policy' => StudentElectionRoundPolicy::VERSION, 'round_id' => $id, 'school_id' => (string) $user->school_id,
                'election_id' => $electionId, 'election_position_id' => $ep, 'source_snapshot_id' => (string) $snapshot->id,
                'source_digest' => $snapshot->digest, 'parent_round_id' => $previous?->id, 'sequence' => (int) ($previous->sequence ?? 0) + 1,
                'kind' => $next['kind'], 'seats' => 1, 'opens_at' => $start->toISOString(), 'closes_at' => $end->toISOString(),
                'timezone' => $timezone, 'electorate' => $electorate->ids, 'electorate_digest' => $electorate->digest,
                'candidates' => array_values(array_filter($position['candidates'], fn ($c) => in_array($c['candidate_id'], $next['candidate_ids'], true)))];
            $this->insert('student_election_rounds', ['id' => $id, 'school_id' => $user->school_id, 'election_id' => $electionId,
                'election_position_id' => $ep, 'source_snapshot_id' => $snapshot->id, 'parent_round_id' => $d['parent_round_id'],
                'sequence' => $d['sequence'], 'kind' => $next['kind'], 'status' => 'scheduled', 'definition' => json_encode($d, JSON_THROW_ON_ERROR),
                'definition_digest' => StudentElectionOutcomePolicy::digest($d), 'created_by' => $user->id, 'created_at' => now()]);
            foreach ($electorate->ids as $learner) {
                $this->insert('student_election_round_voters', ['id' => (string) Str::uuid(), 'round_id' => $id, 'learner_id' => $learner]);
            }
            $this->audit($election, $user, 'round_scheduled', $id, ['definition_digest' => StudentElectionOutcomePolicy::digest($d)]);

            return $this->summary($this->round($election, $id));
        });
    }

    public function transition(User $user, string $electionId, string $id, string $to): array
    {
        $user = $this->actor($user, 'manage_election_voters');

        return DB::transaction(function () use ($user, $electionId, $id, $to) {
            $e = StudentElection::current()->where('school_id', $user->school_id)->whereKey($electionId)->lockForUpdate()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $user = $this->actor($user, 'manage_election_voters');
            if ($e->status !== 'tallied') {
                $this->deny('election', 'Election is not awaiting resolution.');
            }
            $this->resolve(app(StudentElectionResultService::class)->verifiedForRounds($user, $e));
            $round = $this->round($e, $id);
            $d = $this->definition($round);
            if (! (($round->status === 'scheduled' && $to === 'open') || ($round->status === 'open' && $to === 'closed'))) {
                $this->deny('status', 'Round transition is not permitted.');
            }
            if ($to === 'open' && (now()->lt(CarbonImmutable::parse($d['opens_at'])) || now()->gte(CarbonImmutable::parse($d['closes_at'])))) {
                $this->deny('window', 'Round voting window is not active.');
            }
            if ($to === 'open') {
                app(StudentElectionCommercialAccess::class)->opened($e, $user->id, $round);
            }
            if (DB::table('student_election_rounds')->where('id', $id)->update(['status' => $to]) !== 1) {
                throw new \RuntimeException('Round transition failed.');
            }
            $this->audit($e, $user, 'round_'.$to, $id);

            return $this->summary($this->round($e, $id));
        });
    }

    public function tally(User $user, string $electionId, string $id): array
    {
        $user = $this->actor($user, 'tally_student_elections');

        return DB::transaction(function () use ($user, $electionId, $id) {
            $e = StudentElection::current()->where('school_id', $user->school_id)->whereKey($electionId)->lockForUpdate()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $user = $this->actor($user, 'tally_student_elections');
            if ($e->status !== 'tallied') {
                $this->deny('election', 'Election is not awaiting resolution.');
            }
            $this->resolve(app(StudentElectionResultService::class)->verifiedForRounds($user, $e));
            $round = $this->round($e, $id);
            if (! in_array($round->status, ['closed', 'tallied'], true)) {
                $this->deny('status', 'Round must be closed before tally.');
            }
            if ($round->status === 'tallied') {
                return $this->summary($round, true);
            }
            $outcome = $this->aggregate($round, $this->definition($round));
            $digest = StudentElectionOutcomePolicy::digest($outcome);
            if (DB::table('student_election_rounds')->where('id', $id)->update(['status' => 'tallied',
                'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR), 'outcome_digest' => $digest]) !== 1) {
                throw new \RuntimeException('Round tally failed.');
            }
            $this->audit($e, $user, 'round_tallied', $id, ['outcome_digest' => $digest]);

            return $this->summary($this->round($e, $id), true);
        });
    }

    private function summary(object $round, bool $withOutcome = false): array
    {
        $d = $this->definition($round);
        $data = ['id' => $round->id, 'election_id' => $round->election_id, 'election_position_id' => $round->election_position_id,
            'parent_round_id' => $round->parent_round_id, 'sequence' => (int) $round->sequence,
            'kind' => $round->kind, 'status' => $round->status, 'policy' => $d['policy'],
            'opens_at' => $d['opens_at'], 'closes_at' => $d['closes_at'], 'definition_digest' => $round->definition_digest];
        if ($withOutcome && $round->status === 'tallied') {
            $outcome = json_decode($round->outcome, true, 512, JSON_THROW_ON_ERROR);
            $data['outcome'] = $outcome;
            foreach ($data['outcome']['rows'] as &$row) {
                unset($row['learner_id']);
            }
            unset($row);
        }

        return $data;
    }

    public function index(User $user, string $electionId): array
    {
        $user = $this->actor($user, 'tally_student_elections');

        return DB::transaction(function () use ($user, $electionId) {
            $e = StudentElection::current()->where('school_id', $user->school_id)->whereKey($electionId)->sharedLock()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $user = $this->actor($user, 'tally_student_elections');
            $this->resolve(app(StudentElectionResultService::class)->verifiedForRounds($user, $e));

            return DB::table('student_election_rounds')->where('election_id', $electionId)->orderBy('sequence')->orderBy('id')->get()
                ->map(fn ($r) => $this->summary($r, true))->all();
        });
    }

    private function attendance(object $voter, array $d): void
    {
        if (! $voter->attendance_id) {
            $this->deny('attendance', 'Current finalized daily presence is required.');
        }
        $record = DB::table('learner_attendance')->where('id', $voter->attendance_id)->first();
        if (! $record || ! $record->attendance_register_id) {
            $this->deny('attendance', 'Finalized daily register evidence is missing.');
        }
        // Match AttendanceService lock order: register first, then its record.
        $register = DB::table('attendance_registers')->where('id', $record->attendance_register_id)->sharedLock()->firstOrFail();
        $record = DB::table('learner_attendance')->where('id', $voter->attendance_id)->sharedLock()->firstOrFail();
        $code = DB::table('attendance_statuses')->where('id', $record->attendance_status_id)->where('active', true)->value('status_code');
        $learner = DB::table('learners')->where('id', $voter->learner_id)->where('school_id', $d['school_id'])
            ->where('active', true)->where('portal_enabled', true)->where('is_deleted', false)->first();
        $today = now($d['timezone'])->toDateString();
        if (! $learner || $record->learner_id !== $voter->learner_id || $record->school_id !== $d['school_id']
            || $register->school_id !== $d['school_id'] || $record->attendance_register_id !== $register->id
            || $record->grade_id !== $learner->grade_id || $record->stream_id !== $learner->stream_id
            || $register->grade_id !== $learner->grade_id || $register->stream_id !== $learner->stream_id
            || $register->is_deleted || ! in_array($register->status, ['finalized', 'corrected'], true)
            || $register->register_type !== 'daily' || ! $record->finalized
            || (string) $record->attendance_date !== $today || (string) $register->attendance_date !== $today
            || ! in_array(strtoupper((string) $code), ['PRESENT', 'LATE'], true)) {
            $this->deny('attendance', 'Learner is not confirmed present in a current finalized daily register.');
        }
    }

    // Internal primitives only. No learner/teacher HTTP route is enabled here.
    // Classroom devices/session binding and delegated teacher approval remain
    // a separate release gate; administrative permission cannot bypass attendance.
    public function approve(User $officer, string $electionId, string $roundId, string $learnerId, string $attendanceId): string
    {
        $officer = $this->actor($officer, 'manage_election_voters');

        return DB::transaction(function () use ($officer, $electionId, $roundId, $learnerId, $attendanceId) {
            $e = StudentElection::current()->where('school_id', $officer->school_id)->whereKey($electionId)->sharedLock()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $round = $this->round($e, $roundId);
            $d = $this->definition($round);
            if ($e->status !== 'tallied' || $round->status !== 'open') {
                $this->deny('round', 'Round is not open.');
            }
            if (! in_array($learnerId, $d['electorate'], true)) {
                $this->deny('electorate', 'Learner is outside the original electorate.');
            }
            $v = DB::table('student_election_round_voters')->where('round_id', $roundId)->where('learner_id', $learnerId)->lockForUpdate()->firstOrFail();
            if ($v->has_voted) {
                $this->deny('vote', 'Learner has already participated.');
            }
            $v->attendance_id = $attendanceId;
            $this->attendance($v, $d);
            $officer = $this->actor($officer, 'manage_election_voters');
            app(StudentElectionCommercialAccess::class)->live($e, $round);
            $this->window($d);
            $token = bin2hex(random_bytes(32));
            DB::table('student_election_round_voters')->where('id', $v->id)->update(['approval_hash' => hash('sha256', $token),
                'approved_by' => $officer->id, 'attendance_id' => $attendanceId, 'approval_expires_at' => now()->addMinutes(2)->utc()->toIso8601String()]);

            return $token;
        });
    }

    private function window(array $d): void
    {
        $time = now();
        if ($time->lt(CarbonImmutable::parse($d['opens_at'])) || $time->gte(CarbonImmutable::parse($d['closes_at']))) {
            $this->deny('window', 'Round voting window is not active.');
        }
    }

    public function cast(User $user, string $electionId, string $roundId, string $token, string $choice, ?string $candidate = null): array
    {
        $learner = app(StudentElectionLearnerAccess::class)->learner($user);

        return DB::transaction(function () use ($user, $learner, $electionId, $roundId, $token, $choice, $candidate) {
            $e = StudentElection::current()->where('school_id', $user->school_id)->whereKey($electionId)->sharedLock()->firstOrFail();
            app(StudentElectionApplicationService::class)->assertNotHeld($e);
            $round = $this->round($e, $roundId);
            $d = $this->definition($round);
            if ($e->status !== 'tallied' || $round->status !== 'open') {
                $this->deny('round', 'Round is not open.');
            }
            if (! in_array((string) $learner->id, $d['electorate'], true)) {
                $this->deny('electorate', 'Learner is outside the original electorate.');
            }
            $v = DB::table('student_election_round_voters')->where('round_id', $roundId)->where('learner_id', $learner->id)->lockForUpdate()->firstOrFail();
            if ($v->has_voted || ! $v->approval_hash || ! hash_equals($v->approval_hash, hash('sha256', $token))) {
                $this->deny('vote', 'A fresh unused approval is required.');
            }
            $this->attendance($v, $d);
            $fresh = User::withoutGlobalScopes()->where('school_id', $user->school_id)->findOrFail($user->id);
            $this->access->assertAccessible($fresh);
            if (app(StudentElectionLearnerAccess::class)->learner($fresh)->id !== $learner->id) {
                $this->deny('vote', 'Learner identity changed.');
            }
            $officer = User::withoutGlobalScopes()->where('school_id', $user->school_id)->findOrFail($v->approved_by);
            $this->actor($officer, 'manage_election_voters');
            // All potentially blocking locks precede the fresh cutoff check.
            app(StudentElectionCommercialAccess::class)->live($e, $round);
            $this->window($d);
            if (! $v->approval_expires_at || now()->gte(CarbonImmutable::parse($v->approval_expires_at))) {
                $this->deny('vote', 'Approval expired.');
            }
            $allowed = $d['kind'] === 'confirmation' ? ['approve', 'reject', 'abstain'] : ['selection', 'abstain'];
            if (! in_array($choice, $allowed, true) || ($choice !== 'selection' && $candidate !== null)
                || ($choice === 'selection' && ! in_array($candidate, array_column($d['candidates'], 'candidate_id'), true))) {
                $this->deny('choice', 'Ballot does not match the frozen round.');
            }
            $this->insert('student_election_round_ballots', ['id' => (string) Str::uuid(), 'round_id' => $roundId,
                'candidate_id' => $candidate, 'choice' => $choice, 'random_marker' => hash('sha256', random_bytes(32))]);
            if (DB::table('student_election_round_voters')->where('id', $v->id)->update(['has_voted' => true,
                'approval_hash' => null, 'approved_by' => null, 'attendance_id' => null, 'approval_expires_at' => null]) !== 1) {
                throw new \RuntimeException('Round participation persistence failed.');
            }

            return ['confirmed' => true, 'election_id' => $electionId, 'round_id' => $roundId];
        });
    }
}
