<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class StudentElectionStationService
{
    public function __construct(private readonly StudentElectionClassroomAccess $access, private readonly StudentElectionStationBallotService $ballots) {}

    private function election(string $school, string $id, bool $exclusive = false): StudentElection
    {
        $this->access->enabled();
        $q = StudentElection::current()->where('school_id', $school)->whereKey($id);

        $e = ($exclusive ? $q->lockForUpdate() : $q->sharedLock())->firstOrFail();
        app(StudentElectionApplicationService::class)->assertNotHeld($e);

        return $e;
    }

    private function station(StudentElection $e, string $id): \stdClass
    {
        return DB::table('student_election_stations')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function assigned(StudentElection $e, object $station): object
    {
        $a = $this->access->assignment($e, $station->teacher_assignment_id);
        if ($a->stream_id !== $station->stream_id) {
            $this->access->fail('assignment', 'Station classroom changed.');
        }
        $teacher = User::withoutGlobalScopes()->where('school_id', $e->school_id)->findOrFail($a->user_id);
        $this->access->teacher($teacher, $e, $a->id);

        return $a;
    }

    private function deviceTime(object $station): void
    {
        abort_unless($station->active && $station->device_hash && $station->device_expires_at
            && now()->lt(CarbonImmutable::parse($station->device_expires_at)), 401);
    }

    private function summary(object $station): array
    {
        return ['id' => $station->id, 'election_id' => $station->election_id, 'label' => $station->label,
            'teacher_assignment_id' => $station->teacher_assignment_id, 'stream_id' => $station->stream_id, 'active' => (bool) $station->active];
    }

    private function audit(StudentElection $e, User $actor, string $action, string $station): void
    {
        if (in_array($action, ['station_registered', 'station_pairing_issued'], true)) {
            app(StudentElectionCommercialAccess::class)->normal($e->school_id);
        }
        if (! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $e->school_id,
            'election_id' => $e->id, 'actor_user_id' => $actor->id, 'action' => $action, 'entity_type' => 'station',
            'entity_id' => $station, 'metadata' => '{}', 'created_at' => now()])) {
            throw new \RuntimeException('Station audit failed.');
        }
    }

    public function register(User $user, string $election, string $assignment, string $label): array
    {
        $user = $this->access->actor($user, 'manage_student_elections');
        $this->access->actor($user, 'manage_election_voters');

        return DB::transaction(function () use ($user, $election, $assignment, $label): array {
            $e = $this->election($user->school_id, $election, true);
            app(StudentElectionCommercialAccess::class)->normal($e->school_id);
            if (! in_array($e->status, ['draft', 'nominations_open', 'nominations_closed', 'campaigning', 'voting_open', 'tallied'], true)) {
                $this->access->fail('election', 'Station registration is unavailable.');
            }
            $a = $this->access->assignment($e, $assignment);
            $user = $this->access->actor($user, 'manage_student_elections');
            $this->access->actor($user, 'manage_election_voters');
            $label = strtolower(trim(preg_replace('/\s+/', ' ', $label)));
            if ($label === '' || strlen($label) > 60 || DB::table('student_election_stations')->where('election_id', $e->id)->where('stream_id', $a->stream_id)->where('label', $label)->exists()) {
                $this->access->fail('label', 'Use a unique classroom device label of at most 60 bytes.');
            }
            $id = (string) Str::uuid();
            if (! DB::table('student_election_stations')->insert(['id' => $id, 'school_id' => $e->school_id, 'election_id' => $e->id,
                'teacher_assignment_id' => $a->id, 'stream_id' => $a->stream_id, 'label' => $label])) {
                throw new \RuntimeException('Station persistence failed.');
            }
            $this->audit($e, $user, 'station_registered', $id);

            return $this->summary($this->station($e, $id));
        });
    }

    public function pair(User $user, string $election, string $station): array
    {
        $this->access->actor($user, 'operate_student_election_stations');

        return DB::transaction(/** @return array<string, mixed> */ function () use ($user, $election, $station): array {
            $e = $this->election($user->school_id, $election, true);
            app(StudentElectionCommercialAccess::class)->normal($e->school_id);
            $s = $this->station($e, $station);
            $this->access->teacher($user, $e, $s->teacher_assignment_id);
            if (! $s->active || ! in_array($e->status, ['voting_open', 'tallied'], true)) {
                $this->access->fail('station', 'Station pairing is unavailable.');
            }
            if (DB::table('student_election_station_sessions')->where('station_id', $s->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                $this->access->fail('station', 'Cancel or expire the active request before rotating this device.');
            }
            $token = bin2hex(random_bytes(32));
            DB::table('student_election_stations')->where('id', $s->id)->update(['pairing_hash' => hash('sha256', $token),
                'pairing_expires_at' => now()->addMinutes(2)->utc()->toIso8601String(), 'device_hash' => null, 'device_expires_at' => null]);
            $this->audit($e, $user, 'station_pairing_issued', $s->id);

            return ['station_id' => $s->id, 'pairing_token' => $token];
        });
    }

    public function activate(string $station, string $token): array
    {
        $this->access->enabled();
        $s = DB::table('student_election_stations')->where('id', $station)->first();
        abort_unless($s !== null, 401);

        return DB::transaction(/** @return array<string, mixed> */ function () use ($s, $token): array {
            $e = $this->election($s->school_id, $s->election_id);
            $s = $this->station($e, $s->id);
            $this->assigned($e, $s);
            abort_unless($s->active && in_array($e->status, ['voting_open', 'tallied'], true) && $s->pairing_hash
                && hash_equals($s->pairing_hash, hash('sha256', $token)) && $s->pairing_expires_at
                && now()->lt(CarbonImmutable::parse($s->pairing_expires_at)), 401);
            $secret = bin2hex(random_bytes(32));
            $expires = CarbonImmutable::now($this->access->timezone($e))->endOfDay()->min(CarbonImmutable::now()->addHours(6));
            DB::table('student_election_stations')->where('id', $s->id)->update(['pairing_hash' => null, 'pairing_expires_at' => null,
                'device_hash' => hash('sha256', $secret), 'device_expires_at' => $expires->utc()->toIso8601String()]);

            return ['station_id' => $s->id, 'label' => $s->label, 'credential' => $s->id.'.'.$secret];
        });
    }

    private function device(string $credential): array
    {
        $this->access->enabled();
        abort_unless(preg_match('/^([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $credential, $m), 401);
        abort_unless(Str::isUuid($m[1]), 401);
        $s = DB::table('student_election_stations')->where('id', $m[1])->first();
        abort_unless($s !== null, 401);
        $e = $this->election($s->school_id, $s->election_id);
        $s = $this->station($e, $s->id);
        abort_unless($s->active && $s->device_hash && hash_equals($s->device_hash, hash('sha256', $m[2]))
            && $s->device_expires_at && now()->lt(CarbonImmutable::parse($s->device_expires_at)), 401);
        $a = $this->assigned($e, $s);

        return [$e, $s, $a];
    }

    private function erase(object $session, string $status): void
    {
        if (DB::table('student_election_station_sessions')->where('id', $session->id)->update(['status' => $status,
            'learner_id' => null, 'approved_by' => null, 'attendance_id' => null, 'expires_at' => null,
            'definition' => null, 'definition_digest' => null]) !== 1) {
            throw new \RuntimeException('Session consumption failed.');
        }
    }

    private function lockSession(object $s, string $id): \stdClass
    {
        $row = DB::table('student_election_station_sessions')->where('station_id', $s->id)->where('id', $id)->firstOrFail();
        if ($row->election_id !== $s->election_id) {
            $this->access->fail('session', 'Session election scope is inconsistent.');
        }
        if ($row->learner_id) {
            DB::table('learners')->where('id', $row->learner_id)->where('school_id', $s->school_id)->lockForUpdate()->firstOrFail();
        }

        return DB::table('student_election_station_sessions')->where('station_id', $s->id)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function live(object $session): void
    {
        if (! in_array($session->status, ['pending', 'approved'], true) || ! $session->expires_at || now()->gte(CarbonImmutable::parse($session->expires_at))) {
            $this->access->fail('session', 'Voting request or approval expired; request fresh authorization.');
        }
    }

    public function request(string $credential, string $admission): array
    {
        return DB::transaction(function () use ($credential, $admission): array {
            [$e,$s,$a] = $this->device($credential);
            $key = 'election-admission:'.$s->id;
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
            RateLimiter::hit($key, 60);
            $l = DB::table('learners')->where('school_id', $e->school_id)->where('grade_id', $a->grade_id)->where('stream_id', $a->stream_id)
                ->where('admission_no', trim($admission))->lockForUpdate()->first();
            if (! $l) {
                $this->access->fail('admission_no', 'Learner is not available for this classroom.');
            }
            $this->access->learner($e, $a, $l->id);
            $active = DB::table('student_election_station_sessions')->where('election_id', $e->id)->where('learner_id', $l->id)
                ->whereIn('status', ['pending', 'approved'])->lockForUpdate()->first();
            if ($active) {
                if ($active->expires_at && now()->gte(CarbonImmutable::parse($active->expires_at))) {
                    $this->erase($active, 'expired');
                } else {
                    $this->access->fail('session', 'A voting request already exists.');
                }
            }
            if (DB::table('student_election_station_sessions')->where('station_id', $s->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                $this->access->fail('station', 'This classroom device is busy.');
            }
            $definition = $this->ballots->definition($e, $l);
            $this->assigned($e, $s);
            $this->deviceTime($s);
            $this->ballots->assertWindow($definition);
            $id = (string) Str::uuid();
            if (! DB::table('student_election_station_sessions')->insert(['id' => $id, 'election_id' => $e->id, 'station_id' => $s->id,
                'learner_id' => $l->id, 'status' => 'pending', 'expires_at' => now()->addMinutes(5)->utc()->toIso8601String()])) {
                throw new \RuntimeException('Voting request failed.');
            }

            return ['status' => 'waiting_for_teacher', 'session_id' => $id];
        });
    }

    public function decide(User $user, string $election, string $station, string $session, bool $allow, ?string $attendance): array
    {
        $this->access->actor($user, 'operate_student_election_stations');

        return DB::transaction(function () use ($user, $election, $station, $session, $allow, $attendance): array {
            $e = $this->election($user->school_id, $election);
            $s = $this->station($e, $station);
            $a = $this->access->teacher($user, $e, $s->teacher_assignment_id);
            abort_unless($s->active, 403);
            $row = $this->lockSession($s, $session);
            $this->live($row);
            if ($row->status !== 'pending') {
                $this->access->fail('session', 'Request has already been decided.');
            }
            $l = $this->access->learner($e, $a, $row->learner_id);
            if (! $allow) {
                $this->erase($row, 'denied');
                $this->audit($e, $user, 'station_request_denied', $s->id);

                return ['status' => 'denied'];
            }
            $this->access->attendance($e, $a, $l, (string) $attendance);
            $definition = $this->ballots->definition($e, $l);
            $this->access->teacher($user, $e, $s->teacher_assignment_id, true);
            $this->deviceTime($s);
            $this->ballots->assertWindow($definition);
            $this->live($row);
            DB::table('student_election_station_sessions')->where('id', $row->id)->update(['status' => 'approved', 'approved_by' => $user->id,
                'attendance_id' => $attendance, 'expires_at' => now()->addMinutes(2)->utc()->toIso8601String(),
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'definition_digest' => StudentElectionOutcomePolicy::digest($definition)]);
            $this->audit($e, $user, 'station_request_approved', $s->id);

            return ['status' => 'approved'];
        });
    }

    public function state(string $credential): array
    {
        return DB::transaction(/** @return array<string, mixed> */ function () use ($credential): array {
            [$e,$s,$a] = $this->device($credential);
            $row = DB::table('student_election_station_sessions')->where('station_id', $s->id)->whereIn('status', ['pending', 'approved'])->first();
            if (! $row) {
                return ['status' => 'free', 'label' => $s->label];
            }
            $row = $this->lockSession($s, $row->id);
            if (! $row->expires_at || now()->gte(CarbonImmutable::parse($row->expires_at))) {
                $this->erase($row, 'expired');

                return ['status' => 'free', 'label' => $s->label];
            }
            $this->access->learner($e, $a, $row->learner_id);
            if ($row->status === 'pending') {
                return ['status' => 'waiting_for_teacher'];
            }
            $user = User::withoutGlobalScopes()->where('school_id', $e->school_id)->findOrFail($row->approved_by);
            $this->access->teacher($user, $e, $s->teacher_assignment_id);
            $definition = json_decode($row->definition, true, 512, JSON_THROW_ON_ERROR);
            $fresh = $this->ballots->definition($e, $this->access->learner($e, $a, $row->learner_id));
            if (! hash_equals((string) $row->definition_digest, StudentElectionOutcomePolicy::digest($fresh))) {
                $this->access->fail('ballots', 'Approved ballot changed; request fresh authorization.');
            }
            $this->deviceTime($s);
            $this->ballots->assertWindow($definition);

            return ['status' => 'approved', 'session_id' => $row->id, 'definition_digest' => $row->definition_digest,
                'ballot' => $this->ballots->publicDefinition($definition)];
        });
    }

    public function submit(string $credential, string $session, string $digest, array $answers): array
    {
        return DB::transaction(function () use ($credential, $session, $digest, $answers): array {
            [$e,$s,$a] = $this->device($credential);
            $row = $this->lockSession($s, $session);
            $this->live($row);
            if ($row->status !== 'approved') {
                $this->access->fail('session', 'Teacher authorization is required.');
            }
            $l = $this->access->learner($e, $a, $row->learner_id);
            $this->access->attendance($e, $a, $l, (string) $row->attendance_id);
            $definition = $this->ballots->definition($e, $l, true);
            if (! hash_equals((string) $row->definition_digest, $digest)
                || ! hash_equals($digest, StudentElectionOutcomePolicy::digest($definition))
                || ! hash_equals($digest, StudentElectionOutcomePolicy::digest(json_decode($row->definition, true, 512, JSON_THROW_ON_ERROR)))) {
                $this->access->fail('ballots', 'Approved ballot changed; fresh teacher authorization is required.');
            }
            $user = User::withoutGlobalScopes()->where('school_id', $e->school_id)->findOrFail($row->approved_by);
            $this->access->teacher($user, $e, $s->teacher_assignment_id, true);
            $this->access->learner($e, $a, $l->id);
            $this->deviceTime($s);
            $this->live($row);
            $this->ballots->assertWindow($definition);
            $this->ballots->write($e, $definition, $answers);
            $this->erase($row, 'submitted');

            return ['confirmed' => true, 'message' => 'Your vote has been recorded.'];
        });
    }

    public function queue(User $user, string $election, string $assignment): array
    {
        $this->access->actor($user, 'operate_student_election_stations');

        return DB::transaction(function () use ($user, $election, $assignment): array {
            $e = $this->election($user->school_id, $election);
            $a = $this->access->teacher($user, $e, $assignment);
            $stations = DB::table('student_election_stations')->where('school_id', $e->school_id)->where('election_id', $e->id)
                ->where('teacher_assignment_id', $a->id)->orderBy('label')->get();
            $rows = DB::table('student_election_station_sessions as ss')->join('learners as l', 'l.id', '=', 'ss.learner_id')
                ->whereIn('ss.station_id', $stations->pluck('id'))->whereIn('ss.status', ['pending', 'approved'])
                ->where('ss.expires_at', '>', now()->utc()->toIso8601String())->where('l.school_id', $e->school_id)
                ->where('l.grade_id', $a->grade_id)->where('l.stream_id', $a->stream_id)
                ->get(['ss.id', 'ss.station_id', 'ss.status', 'l.admission_no', 'l.first_name', 'l.last_name'])
                ->map(fn ($r) => ['session_id' => $r->id, 'station_id' => $r->station_id, 'status' => $r->status,
                    'admission_no' => $r->admission_no, 'display_name' => trim($r->first_name.' '.$r->last_name)])->all();

            $table = $e->status === 'tallied' ? 'student_election_round_voters' : 'student_election_voters';
            $registry = DB::table($table.' as v')->join('learners as l', 'l.id', '=', 'v.learner_id')
                ->where('l.school_id', $e->school_id)->where('l.grade_id', $a->grade_id)->where('l.stream_id', $a->stream_id);
            if ($e->status === 'tallied') {
                $registry->whereIn('v.round_id', DB::table('student_election_rounds')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('status', 'open')->pluck('id'));
            } else {
                $registry->where('v.school_id', $e->school_id)->where('v.election_id', $e->id)->where('v.eligible', true);
            }
            $progress = $registry->selectRaw('v.learner_id, BOOL_AND(v.has_voted) AS completed')->groupBy('v.learner_id')->get();
            $complete = $progress->filter(fn ($r) => in_array($r->completed, [true, 1, '1', 't'], true))->count();

            return ['stations' => $stations->map(fn ($s) => $this->summary($s))->all(), 'requests' => $rows,
                'progress' => ['eligible' => $progress->count(), 'completed' => $complete, 'remaining' => $progress->count() - $complete]];
        });
    }

    public function reset(User $user, string $election, string $station): array
    {
        $this->access->actor($user, 'operate_student_election_stations');

        return DB::transaction(/** @return array<string, mixed> */ function () use ($user, $election, $station): array {
            $e = $this->election($user->school_id, $election, true);
            $s = $this->station($e, $station);
            $this->access->teacher($user, $e, $s->teacher_assignment_id);
            foreach (DB::table('student_election_station_sessions')->where('station_id', $s->id)->whereIn('status', ['pending', 'approved'])->get() as $row) {
                $this->erase($row, 'cancelled');
            }
            $this->audit($e, $user, 'station_reset', $s->id);

            return ['status' => 'free'];
        });
    }

    public function revoke(User $user, string $election, string $station): array
    {
        $this->access->actor($user, 'manage_student_elections');

        return DB::transaction(/** @return array<string, mixed> */ function () use ($user, $election, $station): array {
            $e = $this->election($user->school_id, $election, true);
            $s = $this->station($e, $station);
            $user = $this->access->actor($user, 'manage_student_elections');
            DB::table('student_election_stations')->where('id', $s->id)->update(['active' => false, 'device_hash' => null, 'device_expires_at' => null, 'pairing_hash' => null, 'pairing_expires_at' => null]);
            foreach (DB::table('student_election_station_sessions')->where('station_id', $s->id)->whereIn('status', ['pending', 'approved'])->get() as $row) {
                $this->erase($row, 'cancelled');
            }
            $this->audit($e, $user, 'station_revoked', $s->id);

            return ['revoked' => true];
        });
    }
}
