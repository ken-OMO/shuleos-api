<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportExceptionService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
        private AuthContextService $auth,
    ) {}

    public function append(User $authenticated, string $failureId, array $input): array
    {
        foreach (array_diff(array_keys($input), [
            'expected_version', 'expected_journey_version', 'action',
            'note', 'assigned_to', 'resolution_event_id',
        ]) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }

        $data = Validator::make($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'expected_journey_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'action' => ['required', 'in:follow_up,resolve'],
            'note' => ['required', 'string', 'max:1000'],
            'assigned_to' => ['sometimes', 'required', 'uuid'],
            'resolution_event_id' => ['nullable', 'uuid', 'required_if:action,resolve'],
        ])->validate();

        $data['note'] = trim($data['note']);
        if ($data['note'] === '') {
            throw ValidationException::withMessages(['note' => ['A nonblank action note is required.']]);
        }
        if ($data['action'] === 'follow_up' && array_key_exists('resolution_event_id', $input)) {
            throw ValidationException::withMessages([
                'resolution_event_id' => ['Resolution evidence is prohibited for a follow-up.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($authenticated, $failureId, $data): array {
                $actor = $this->access->exceptionActor($authenticated, $failureId);
                $school = (string) $actor->school_id;
                $failure = $this->failure($school, $failureId);

                DB::table('learners')->where('school_id', $school)
                    ->where('id', $failure->learner_id)->lockForUpdate()->first();
                DB::table('transport_trip_roster')->where('school_id', $school)
                    ->where('id', $failure->roster_id)->lockForUpdate()->first();
                DB::table('transport_journey_events')->where('school_id', $school)
                    ->where('id', $failureId)->lockForUpdate()->first();

                $before = $this->response($failure);
                $trip = DB::table('transport_trips')->where('school_id', $school)
                    ->where('id', $failure->trip_id)->first();
                abort_unless($trip !== null, 404);

                $canAuthorize = $this->auth->hasPermission($actor, 'authorize_transport_handover');
                $responsible = in_array((string) $actor->getKey(), array_filter([
                    (string) $trip->driver_id,
                    $trip->attendant_id === null ? null : (string) $trip->attendant_id,
                    (string) $before['assigned_to'],
                ]), true);

                if ($data['action'] === 'resolve' || array_key_exists('assigned_to', $data)) {
                    abort_unless($canAuthorize, 403);
                } else {
                    abort_unless(
                        $canAuthorize || ($responsible
                            && $this->auth->hasPermission($actor, 'record_transport_journeys')),
                        403
                    );
                }

                abort_unless(hash_equals($before['version'], $data['expected_version']), 409);
                abort_unless(hash_equals(
                    $before['journey_version'], $data['expected_journey_version']
                ), 409, 'Journey custody changed; refresh before acting.');
                abort_unless($before['status'] === 'open', 409);

                $ownerId = $data['assigned_to'] ?? $before['assigned_to'];
                $owner = User::withoutGlobalScopes()->where('school_id', $school)
                    ->whereKey($ownerId)->sharedLock()->first();
                abort_unless($owner !== null, 404);
                $owner->setRelation('school', $actor->school);
                $this->auth->assertAccessible($owner);
                abort_unless(
                    $this->auth->hasPermission($owner, 'record_transport_journeys')
                    || $this->auth->hasPermission($owner, 'authorize_transport_handover'),
                    422, 'Exception owner needs an applicable Transport permission.'
                );

                if ($data['action'] === 'resolve') {
                    abort_unless(DB::table('transport_journey_events')
                        ->where('school_id', $school)
                        ->where('id', $data['resolution_event_id'])->exists(), 404);
                }

                $previous = DB::table('transport_exception_follow_ups')
                    ->where('school_id', $school)->where('failure_event_id', $failureId)
                    ->orderByDesc('sequence')->first();
                $basis = $this->latestJourney($failure);
                $id = (string) Str::uuid();

                DB::table('transport_exception_follow_ups')->insert([
                    'id' => $id,
                    'school_id' => $school,
                    'failure_event_id' => $failureId,
                    'previous_id' => $previous->id ?? null,
                    'basis_event_id' => $basis->id,
                    'action' => $data['action'],
                    'note' => $data['note'],
                    'assigned_to' => $ownerId,
                    'resolution_event_id' => $data['resolution_event_id'] ?? null,
                    'recorded_by' => $actor->getKey(),
                ]);

                $after = $this->response($failure);
                $this->audit->record(
                    $actor, $data['action'], 'transport_exception_follow_ups', $id,
                    [
                        'failure_event_id' => $failureId,
                        'status' => $before['status'],
                        'assigned_to' => $before['assigned_to'],
                    ],
                    [
                        'failure_event_id' => $failureId,
                        'status' => $after['status'],
                        'assigned_to' => $after['assigned_to'],
                        'resolution_event_id' => $data['resolution_event_id'] ?? null,
                        'note' => $data['note'],
                    ]
                );

                return $after;
            });
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23P01', '23505'], true)) {
                abort(409, 'Exception or journey evidence changed.');
            }
            if ($state === '23514') {
                abort(422, 'Exception owner, custody basis or resolution evidence is invalid.');
            }
            throw $exception;
        }
    }

    public function show(User $authenticated, string $failureId): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->response($this->failure((string) $actor->school_id, $failureId));
    }

    private function failure(string $school, string $id): object
    {
        $row = DB::table('transport_journey_events')->where('school_id', $school)
            ->where('id', $id)->where('outcome', 'failed')->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function latestJourney(object $failure): object
    {
        return DB::table('transport_journey_events')->where('school_id', $failure->school_id)
            ->where('roster_id', $failure->roster_id)->orderByDesc('sequence')->firstOrFail();
    }

    private function response(object $failure): array
    {
        $history = DB::table('transport_exception_follow_ups')
            ->where('school_id', $failure->school_id)->where('failure_event_id', $failure->id)
            ->orderBy('sequence')->get();
        $last = $history->last();
        $journey = $this->latestJourney($failure);
        $attendance = DB::table('transport_journey_outcomes')
            ->where('school_id', $failure->school_id)
            ->where('roster_id', $failure->roster_id)->first();

        return [
            'failure_event_id' => (string) $failure->id,
            'roster_id' => (string) $failure->roster_id,
            'boundary' => $failure->boundary,
            'failure_reason' => $failure->failure_reason,
            'original_follow_up' => $failure->follow_up,
            'status' => ($last->action ?? null) === 'resolve' ? 'resolved' : 'open',
            'assigned_to' => (string) ($last->assigned_to ?? $failure->exception_owner_id),
            'version' => hash('sha256', 'transport-exception:'.$failure->id.':'.($last->id ?? 'open')),
            'journey_version' => hash('sha256', 'transport-journey:'.$failure->roster_id.':'.$journey->id
                .($attendance === null ? '' : ':outcome:'.$attendance->id)),
            'attendance_outcome' => $attendance->outcome ?? null,
            'current_custody' => [
                'state' => $journey->state_after,
                'custody_user_id' => $journey->custody_user_id,
                'custody_guardian_id' => $journey->custody_guardian_id,
            ],
            'history' => $history->map(fn ($row) => [
                'id' => (string) $row->id,
                'sequence' => (int) $row->sequence,
                'action' => $row->action,
                'note' => $row->note,
                'assigned_to' => (string) $row->assigned_to,
                'resolution_event_id' => $row->resolution_event_id,
                'custody' => json_decode($row->custody_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'recorded_by' => (string) $row->recorded_by,
                'recorded_at' => $row->recorded_at,
            ])->all(),
        ];
    }
}
