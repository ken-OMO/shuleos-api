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

class TransportEnrolmentService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
        private TransportLearnerReservationService $reservations,
    ) {}

    public function create(User $authenticated, array $input): array
    {
        $this->rejectUnknown($input, [
            'learner_id', 'schedule_id', 'stop_id', 'run_number',
            'effective_from', 'effective_to', 'weekday_mask',
        ]);
        $values = Validator::make($input, [
            'learner_id' => ['required', 'uuid'],
            'schedule_id' => ['required', 'uuid'],
            'stop_id' => ['required', 'uuid'],
            'run_number' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'weekday_mask' => ['required', 'integer', 'between:1,127'],
        ])->validate();

        return $this->write(function () use ($authenticated, $values): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport_enrolments');

            return $this->createEpisode($actor, $values);
        });
    }

    public function transfer(User $authenticated, string $id, array $input): array
    {
        $this->rejectUnknown($input, [
            'expected_version', 'effective_from', 'schedule_id',
            'stop_id', 'run_number', 'reason',
        ]);
        $values = Validator::make($input, [
            'expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'schedule_id' => ['required', 'uuid'],
            'stop_id' => ['required', 'uuid'],
            'run_number' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $reason = trim($values['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return $this->write(function () use ($authenticated, $id, $values, $reason): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport_enrolments');

            $source = DB::table('transport_enrolment_episodes')
                ->where('school_id', $actor->school_id)->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($source !== null, 404);
            $old = $this->response($source);

            abort_unless(hash_equals($old['version'], $values['expected_version']), 409);
            abort_unless($source->ended_before === null, 409, 'Episode already transferred.');

            $cutover = $values['effective_from'];
            abort_unless(
                $cutover >= $source->effective_from && $cutover <= $source->effective_to,
                422,
                'Transfer date must lie within the source episode.'
            );
            $this->assertFutureOrToday($actor, $cutover);

            $targetSchedule = DB::table('transport_service_schedules')
                ->where('school_id', $actor->school_id)
                ->where('id', $values['schedule_id'])->first();
            abort_unless($targetSchedule !== null, 404);
            abort_unless($targetSchedule->direction === $source->direction, 422);

            $links = DB::table('transport_enrolment_reservations')
                ->where('school_id', $actor->school_id)->where('episode_id', $id)
                ->where('service_date', '>=', $cutover)
                ->orderBy('service_date')->get();

            abort_if($links->isEmpty(), 422, 'No service dates remain to transfer.');

            foreach ($links as $link) {
                $reservation = DB::table('transport_learner_reservations')
                    ->where('school_id', $actor->school_id)
                    ->where('id', $link->reservation_id)->lockForUpdate()->first();
                abort_unless($reservation !== null && $reservation->active, 409,
                    'A source reservation has already ended.');

                $this->reservations->end($actor, (string) $reservation->id, [
                    'expected_version' => hash(
                        'sha256', $reservation->id.':'.$reservation->lock_version
                    ),
                    'reason' => $reason,
                ]);
            }

            DB::table('transport_enrolment_episodes')->where('id', $id)->update([
                'ended_before' => $cutover,
                'updated_by' => $actor->id,
            ]);

            $target = $this->createEpisode($actor, [
                'learner_id' => $source->learner_id,
                'schedule_id' => $values['schedule_id'],
                'stop_id' => $values['stop_id'],
                'run_number' => $values['run_number'] ?? $source->run_number,
                'effective_from' => $cutover,
                'effective_to' => $source->effective_to,
                'weekday_mask' => $source->weekday_mask,
            ]);

            $transferId = (string) Str::uuid();
            DB::table('transport_enrolment_transfers')->insert([
                'id' => $transferId,
                'school_id' => $actor->school_id,
                'source_episode_id' => $id,
                'target_episode_id' => $target['id'],
                'effective_from' => $cutover,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                $actor, 'transfer', 'transport_enrolment_transfers', $transferId,
                $old,
                [
                    'source_episode_id' => $id,
                    'target_episode_id' => $target['id'],
                    'effective_from' => $cutover,
                    'reason' => $reason,
                ]
            );

            return ['transfer_id' => $transferId, 'target_episode' => $target];
        });
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');
        $row = DB::table('transport_enrolment_episodes')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $this->response($row);
    }

    private function createEpisode(User $actor, array $values): array
    {
        $this->assertFutureOrToday($actor, $values['effective_from']);
        $start = CarbonImmutable::parse($values['effective_from'], 'UTC');
        $end = CarbonImmutable::parse($values['effective_to'], 'UTC');
        abort_if($end < $start || $start->diffInDays($end) > 365, 422,
            'An enrolment range may cover at most 366 dates.');

        $schedule = DB::table('transport_service_schedules')
            ->where('school_id', $actor->school_id)
            ->where('id', $values['schedule_id'])->lockForUpdate()->first();
        abort_unless($schedule !== null, 404);

        foreach ([
            ['learners', $values['learner_id']],
            ['transport_route_stops', $values['stop_id']],
        ] as [$table, $resource]) {
            abort_unless(
                DB::table($table)->where('school_id', $actor->school_id)
                    ->where('id', $resource)->exists(),
                404
            );
        }

        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            if (((int) $values['weekday_mask'] & (1 << ($day->isoWeekday() - 1))) !== 0) {
                $dates[] = $day->toDateString();
            }
        }
        abort_if($dates === [], 422, 'No selected weekdays occur in this range.');

        $id = (string) Str::uuid();
        DB::table('transport_enrolment_episodes')->insert($values + [
            'id' => $id,
            'school_id' => $actor->school_id,
            'direction' => $schedule->direction,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($dates as $date) {
            $assignment = DB::table('transport_dated_assignments')
                ->where('school_id', $actor->school_id)
                ->where('schedule_id', $schedule->id)
                ->where('service_date', $date)
                ->where('run_number', $values['run_number'] ?? 1)
                ->where('active', true)->lockForUpdate()->first();

            abort_unless($assignment !== null, 409,
                'An active assigned run is required for every selected date.');

            $reservation = $this->reservations->create($actor, [
                'assignment_id' => $assignment->id,
                'learner_id' => $values['learner_id'],
                'stop_id' => $values['stop_id'],
            ]);

            DB::table('transport_enrolment_reservations')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $actor->school_id,
                'episode_id' => $id,
                'reservation_id' => $reservation['id'],
                'service_date' => $date,
            ]);
        }

        $row = DB::table('transport_enrolment_episodes')->where('id', $id)->first();
        abort_unless($row !== null, 404);
        $result = $this->response($row);
        $this->audit->record(
            $actor, 'create', 'transport_enrolment_episodes', $id, [],
            $result + ['reservation_count' => count($dates)]
        );

        return $result;
    }

    private function assertFutureOrToday(User $actor, string $date): void
    {
        $timezone = $actor->school->timezone ?: config('app.timezone');
        abort_if($date < CarbonImmutable::now($timezone)->toDateString(), 422,
            'New enrolments and transfers cannot rewrite past service dates.');
    }

    private function response(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'learner_id' => (string) $row->learner_id,
            'schedule_id' => (string) $row->schedule_id,
            'stop_id' => (string) $row->stop_id,
            'direction' => (string) $row->direction,
            'run_number' => (int) $row->run_number,
            'effective_from' => (string) $row->effective_from,
            'effective_to' => (string) $row->effective_to,
            'weekday_mask' => (int) $row->weekday_mask,
            'ended_before' => $row->ended_before,
            'version' => hash('sha256', $row->id.':'.$row->lock_version),
        ];
    }

    private function rejectUnknown(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw ValidationException::withMessages([$field => 'Unavailable field.']);
            }
        }
    }

    private function write(callable $operation): array
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23P01', '23505'], true)) {
                abort(409, 'Enrolment or capacity conflict.');
            }
            if (($exception->errorInfo[0] ?? null) === '23514') {
                abort(422, 'Enrolment does not match the selected service.');
            }

            throw $exception;
        }
    }
}
