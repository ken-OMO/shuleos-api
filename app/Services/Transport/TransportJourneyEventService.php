<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportJourneyEventService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
        private TransportJourneyTransitionPolicy $policy,
    ) {}

    public function record(User $authenticated, string $rosterId, array $input): array
    {
        $allowed = [
            'expected_version', 'boundary', 'outcome', 'crew_user_id',
            'authorization_id', 'occurred_at', 'verification_method',
            'verification_note', 'receipt_method', 'failure_reason', 'follow_up',
        ];
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }

        $data = Validator::make($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'boundary' => ['required', 'in:pickup,school_arrival,school_departure,destination_dropoff'],
            'outcome' => ['required', 'in:recorded,failed'],
            'crew_user_id' => ['required', 'uuid'],
            'authorization_id' => ['nullable', 'uuid', 'required_if:outcome,recorded'],
            'occurred_at' => [
                'required', 'date',
                'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/',
            ],
            'verification_method' => [
                'nullable', 'required_if:outcome,recorded',
                'in:known_person_in_person,document_checked_in_person',
            ],
            'verification_note' => ['nullable', 'required_if:outcome,recorded', 'string', 'max:500'],
            'receipt_method' => ['required', 'in:none,authenticated_receiver,witnessed_verbal'],
            'failure_reason' => [
                'nullable', 'required_if:outcome,failed',
                'in:person_unavailable,person_unauthorized,identity_not_verified,receipt_not_obtained,handover_refused',
            ],
            'follow_up' => ['nullable', 'required_if:outcome,failed', 'string', 'max:1000'],
        ])->validate();

        foreach (['verification_note', 'follow_up'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
                if ($data[$key] === '') {
                    throw ValidationException::withMessages([$key => ['A nonblank explanation is required.']]);
                }
            }
        }

        $failed = $data['outcome'] === 'failed';
        if (($failed && $data['receipt_method'] !== 'none')
            || (! $failed && (! empty($data['failure_reason']) || ! empty($data['follow_up'])))) {
            throw ValidationException::withMessages(['outcome' => ['Inconsistent outcome evidence.']]);
        }

        try {
            return DB::transaction(function () use ($authenticated, $rosterId, $data, $failed): array {
                $actor = $this->access->actor($authenticated, true);
                $this->access->requirePermission($actor, 'record_transport_journeys');
                $school = (string) $actor->school_id;
                $roster = $this->roster($school, $rosterId);

                DB::table('learners')->where('school_id', $school)
                    ->where('id', $roster->learner_id)->lockForUpdate()->first();
                DB::table('transport_trip_roster')->where('school_id', $school)
                    ->where('id', $rosterId)->lockForUpdate()->first();

                $trip = DB::table('transport_trips')->where('school_id', $school)
                    ->where('id', $roster->trip_id)->first();
                abort_unless($trip !== null, 404);

                $grant = null;
                if (! empty($data['authorization_id'])) {
                    $grant = DB::table('transport_handover_authorizations')
                        ->where('school_id', $school)->where('id', $data['authorization_id'])
                        ->sharedLock()->first();
                    abort_unless($grant !== null, 404);
                }

                $crew = in_array((string) $actor->getKey(), array_filter([
                    (string) $trip->driver_id,
                    $trip->attendant_id === null ? null : (string) $trip->attendant_id,
                ]), true);
                $schoolCounterpart = ! $failed && $grant !== null
                    && $grant->assignment_id === $trip->assignment_id
                    && (($data['boundary'] === 'school_arrival' && $grant->kind === 'school_receiver')
                        || ($data['boundary'] === 'school_departure' && $grant->kind === 'school_releaser'))
                    && (string) $grant->staff_user_id === (string) $actor->getKey();
                abort_unless($crew || $schoolCounterpart, 403);

                abort_unless(DB::table('users')->where('school_id', $school)
                    ->where('id', $data['crew_user_id'])->exists(), 404);

                $previous = $this->latest($school, $rosterId);
                abort_unless(hash_equals(
                    $this->version($rosterId, $previous),
                    $data['expected_version']
                ), 409, 'Journey changed; refresh before recording.');

                $at = CarbonImmutable::parse($data['occurred_at'])->utc();
                $now = CarbonImmutable::now('UTC');

                // Initial online workflow: no future or offline/backlog recording.
                abort_if($at->greaterThan($now) || $at->lessThan($now->subMinutes(15)), 422,
                    'Record an online event within fifteen minutes of occurrence.');

                $context = json_decode($trip->context_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $timezone = $context['timezone'];
                $lastDate = CarbonImmutable::parse($context['completes_at'])
                    ->setTimezone($timezone)->toDateString();
                $eventDate = $at->setTimezone($timezone)->toDateString();
                abort_unless($eventDate >= $trip->service_date && $eventDate <= $lastDate, 422,
                    'Event is outside the dated service.');

                try {
                    $decision = $this->policy->transition(
                        $trip->direction,
                        $previous->state_after ?? 'expected',
                        $data['boundary'],
                        $failed ? 'unverified' : 'verified',
                        $data['receipt_method'],
                        $failed ? $data['failure_reason'] : null,
                    );
                } catch (DomainException $exception) {
                    abort(409, $exception->getMessage());
                }

                $id = (string) Str::uuid();
                DB::table('transport_journey_events')->insert([
                    'id' => $id,
                    'school_id' => $school,
                    'roster_id' => $rosterId,
                    'previous_id' => $previous->id ?? null,
                    'boundary' => $data['boundary'],
                    'outcome' => $data['outcome'],
                    'crew_user_id' => $data['crew_user_id'],
                    'authorization_id' => $data['authorization_id'] ?? null,
                    'occurred_at' => $at->format('Y-m-d H:i:s.uP'),
                    'verification_method' => $data['verification_method'] ?? null,
                    'verification_note' => $data['verification_note'] ?? null,
                    'receipt_method' => $data['receipt_method'],
                    'failure_reason' => $failed ? $data['failure_reason'] : null,
                    'follow_up' => $failed ? $data['follow_up'] : null,
                    'recorded_by' => $actor->getKey(),
                ]);

                $event = DB::table('transport_journey_events')
                    ->where('school_id', $school)->where('id', $id)->first();
                if ($event === null || $event->state_after !== $decision['state']) {
                    throw new \LogicException('Journey policy and stored evidence disagree.');
                }

                $this->audit->record(
                    $actor, 'record', 'transport_journey_events', $id, [], [
                        'roster_id' => $rosterId,
                        'boundary' => $event->boundary,
                        'outcome' => $event->outcome,
                        'state_after' => $event->state_after,
                        'sequence' => (int) $event->sequence,
                    ]
                );

                return $this->response($school, $rosterId);
            });
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23P01', '23505'], true)) {
                abort(409, 'Journey evidence conflicts with the current state.');
            }
            if ($state === '23514') {
                abort(422, 'Handover identity, authorization, receipt or timing is invalid.');
            }
            throw $exception;
        }
    }

    public function show(User $authenticated, string $rosterId): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');
        $this->roster((string) $actor->school_id, $rosterId);

        return $this->response((string) $actor->school_id, $rosterId);
    }

    private function roster(string $school, string $id): object
    {
        $row = DB::table('transport_trip_roster')->where('school_id', $school)
            ->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function latest(string $school, string $id): ?object
    {
        return DB::table('transport_journey_events')->where('school_id', $school)
            ->where('roster_id', $id)->orderByDesc('sequence')->first();
    }

    private function version(string $rosterId, ?object $event): string
    {
        $attendance = DB::table('transport_journey_outcomes')
            ->where('roster_id', $rosterId)->first();

        return hash('sha256', 'transport-journey:'.$rosterId.':'.($event->id ?? 'expected')
            .($attendance === null ? '' : ':outcome:'.$attendance->id));
    }

    private function response(string $school, string $id): array
    {
        $rows = DB::table('transport_journey_events')->where('school_id', $school)
            ->where('roster_id', $id)->orderBy('sequence')->get();
        $latest = $rows->last();
        $attendance = DB::table('transport_journey_outcomes')
            ->where('school_id', $school)->where('roster_id', $id)->first();
        $resolvedIds = DB::table('transport_exception_follow_ups')
            ->where('school_id', $school)->where('action', 'resolve')
            ->whereIn('failure_event_id', $rows->where('outcome', 'failed')->pluck('id'))
            ->pluck('failure_event_id')->all();

        return [
            'roster_id' => $id,
            'state' => $attendance->outcome ?? $latest->state_after ?? 'expected',
            'custody_user_id' => $latest->custody_user_id ?? null,
            'custody_guardian_id' => $latest->custody_guardian_id ?? null,
            'version' => $this->version($id, $latest),
            // Later success never silently resolves a prior failed attempt.
            'unresolved_failure_ids' => $rows->where('outcome', 'failed')
                ->reject(fn ($row) => in_array($row->id, $resolvedIds, true))
                ->pluck('id')->values()->all(),
            'events' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'sequence' => (int) $row->sequence,
                'boundary' => $row->boundary,
                'outcome' => $row->outcome,
                'state_before' => $row->state_before,
                'state_after' => $row->state_after,
                'from_person' => json_decode($row->from_person, true, 512, JSON_THROW_ON_ERROR),
                'to_person' => json_decode($row->to_person, true, 512, JSON_THROW_ON_ERROR),
                'authorization' => $row->authorization_snapshot === null ? null
                    : json_decode($row->authorization_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'location' => json_decode($row->location_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'verification_method' => $row->verification_method,
                'verification_note' => $row->verification_note,
                'receipt_state' => $row->receipt_state,
                'confirmed_by' => $row->confirmed_by,
                'failure_reason' => $row->failure_reason,
                'follow_up' => $row->follow_up,
                'exception_owner_id' => $row->exception_owner_id,
                'occurred_at' => $row->occurred_at,
                'recorded_by' => $row->recorded_by,
                'recorded_at' => $row->recorded_at,
            ])->all(),
        ];
    }
}
