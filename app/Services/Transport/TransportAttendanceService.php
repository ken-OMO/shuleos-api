<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportAttendanceService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function recordOutcome(User $authenticated, string $rosterId, array $input): array
    {
        $data = $this->validate($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'outcome' => ['required', 'in:missed_pickup,not_boarded,cancelled'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->write(function () use ($authenticated, $rosterId, $data): array {
            $actor = $this->access->attendanceActor($authenticated, $rosterId);
            $this->access->requirePermission($actor, 'record_transport_journeys');
            $roster = DB::table('transport_trip_roster')->where('school_id', $actor->school_id)
                ->where('id', $rosterId)->first();
            abort_unless($roster !== null, 404);
            $trip = $this->trip((string) $actor->school_id, $roster->trip_id, true);
            $this->requireCrew($actor, $trip);

            DB::table('learners')->where('school_id', $actor->school_id)
                ->where('id', $roster->learner_id)->lockForUpdate()->first();
            DB::table('transport_trip_roster')->where('school_id', $actor->school_id)
                ->where('id', $rosterId)->lockForUpdate()->first();

            $last = DB::table('transport_journey_events')->where('school_id', $actor->school_id)
                ->where('roster_id', $rosterId)->orderByDesc('sequence')->first();
            $existing = DB::table('transport_journey_outcomes')
                ->where('school_id', $actor->school_id)->where('roster_id', $rosterId)->first();
            $version = hash('sha256', 'transport-journey:'.$rosterId.':'.($last->id ?? 'expected')
                .($existing === null ? '' : ':outcome:'.$existing->id));
            abort_unless(hash_equals($version, $data['expected_version']), 409);
            abort_unless($existing === null, 409);

            if ($data['outcome'] !== 'cancelled') {
                $assignment = DB::table('transport_dated_assignments')
                    ->where('school_id', $actor->school_id)
                    ->where('id', $trip->assignment_id)->first();
                abort_unless($assignment !== null, 404);
                abort_if(
                    CarbonImmutable::now('UTC')->lessThan(
                        CarbonImmutable::parse($assignment->completes_at)
                    ),
                    422,
                    'Wait until the planned service window ends before declaring non-attendance.'
                );
            }

            $id = (string) Str::uuid();
            DB::table('transport_journey_outcomes')->insert([
                'id' => $id, 'school_id' => $actor->school_id,
                'roster_id' => $rosterId, 'outcome' => $data['outcome'],
                'reason' => $data['reason'], 'recorded_by' => $actor->getKey(),
            ]);
            $this->audit->record(
                $actor, 'record', 'transport_journey_outcomes', $id, [], [
                    'roster_id' => $rosterId, 'outcome' => $data['outcome'],
                    'reason' => $data['reason'],
                ]
            );

            return $this->response($trip);
        });
    }

    public function completeTrip(User $authenticated, string $tripId, array $input): array
    {
        $data = $this->validate($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->write(function () use ($authenticated, $tripId, $data): array {
            $actor = $this->access->completionActor($authenticated, $tripId);
            $this->access->requirePermission($actor, 'record_transport_journeys');
            $trip = $this->trip((string) $actor->school_id, $tripId, true);
            $this->requireCrew($actor, $trip);
            $before = $this->response($trip);
            abort_unless(hash_equals($before['version'], $data['expected_version']), 409);
            $history = $before['completion_history'];
            $previous = $history === [] ? null : $history[array_key_last($history)];
            abort_if(($previous['status'] ?? null) === 'completed', 409);

            $id = (string) Str::uuid();
            DB::table('transport_trip_completions')->insert([
                'id' => $id, 'school_id' => $actor->school_id,
                'trip_id' => $tripId, 'previous_id' => $previous['id'] ?? null,
                'reason' => $data['reason'],
                'snapshot' => json_encode($before['attendance'], JSON_THROW_ON_ERROR),
                'recorded_by' => $actor->getKey(),
            ]);
            $after = $this->response($trip);
            $latest = $after['completion_history'][array_key_last($after['completion_history'])];
            $this->audit->record(
                $actor, 'complete', 'transport_trip_completions', $id, [], [
                    'trip_id' => $tripId, 'status' => $latest['status'],
                    'snapshot' => $latest['snapshot'], 'reason' => $data['reason'],
                ]
            );

            return $after;
        });
    }

    public function showTrip(User $authenticated, string $tripId): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->response($this->trip((string) $actor->school_id, $tripId));
    }

    private function trip(string $school, string $id, bool $lock = false): object
    {
        $query = DB::table('transport_trips')->where('school_id', $school)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $trip = $query->first();
        abort_unless($trip !== null, 404);

        return $trip;
    }

    private function requireCrew(User $actor, object $trip): void
    {
        abort_unless(in_array((string) $actor->getKey(), array_filter([
            (string) $trip->driver_id,
            $trip->attendant_id === null ? null : (string) $trip->attendant_id,
        ]), true), 403);
    }

    private function response(object $trip): array
    {
        $raw = DB::selectOne(
            'SELECT public.transport_trip_attendance_snapshot(?::uuid, ?::uuid) AS snapshot',
            [$trip->school_id, $trip->id]
        )->snapshot;
        $history = DB::table('transport_trip_completions')
            ->where('school_id', $trip->school_id)->where('trip_id', $trip->id)
            ->orderBy('sequence')->get();
        $last = $history->last();

        return [
            'trip_id' => (string) $trip->id,
            'version' => hash('sha256',
                'transport-completion:'.$trip->id.':'.($last->id ?? 'open').':'.$raw),
            'attendance' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            'completion_history' => $history->map(fn ($row) => [
                'id' => (string) $row->id, 'sequence' => (int) $row->sequence,
                'status' => $row->status, 'reason' => $row->reason,
                'snapshot' => json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR),
                'recorded_by' => (string) $row->recorded_by,
                'recorded_at' => $row->recorded_at,
            ])->all(),
        ];
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
        $data = Validator::make($input, $rules)->validate();
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => ['A nonblank reason is required.']]);
        }

        return $data;
    }

    private function write(callable $operation): array
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23P01', '23505'], true)) {
                abort(409, 'Journey attendance or completion state conflicts.');
            }
            if ($state === '23514') {
                abort(422, 'Attendance outcome or completion evidence is invalid.');
            }
            throw $exception;
        }
    }
}
