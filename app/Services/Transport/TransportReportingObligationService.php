<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportReportingObligationService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
        private AuthContextService $auth,
    ) {}

    public function createPolicy(User $authenticated, array $input): array
    {
        $data = $this->validate($input, [
            'schedule_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'weekday_mask' => ['required', 'integer', 'between:1,127'],
            'deadline_time' => ['required', 'date_format:H:i'],
            'deadline_day_offset' => ['required', 'integer', 'between:0,2'],
            'grace_minutes' => ['required', 'integer', 'between:0,1440'],
        ]);

        return $this->write(function () use ($authenticated, $data): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $schedule = DB::table('transport_service_schedules')
                ->where('school_id', $actor->school_id)->where('id', $data['schedule_id'])
                ->sharedLock()->first();
            abort_unless($schedule !== null, 404);
            abort_if(
                $data['effective_from'] < CarbonImmutable::now($schedule->timezone)->toDateString(),
                422, 'New reporting policy episodes cannot start in the past.'
            );

            $id = (string) Str::uuid();
            DB::table('transport_reporting_policies')->insert([
                ...$data, 'id' => $id, 'school_id' => $actor->school_id,
                'created_by' => $actor->getKey(),
            ]);
            $result = [
                'id' => $id, ...$data, 'timezone' => $schedule->timezone,
            ];
            $this->audit->record(
                $actor, 'configure', 'transport_reporting_policies', $id, [], $result
            );

            return $result;
        });
    }

    public function materialize(User $authenticated, string $policyId): array
    {
        return $this->write(function () use ($authenticated, $policyId): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $policy = DB::table('transport_reporting_policies')
                ->where('school_id', $actor->school_id)->where('id', $policyId)
                ->sharedLock()->first();
            abort_unless($policy !== null, 404);
            $date = CarbonImmutable::now($policy->timezone)->toDateString();

            $existing = DB::table('transport_reporting_obligations')
                ->where('school_id', $actor->school_id)
                ->where('schedule_id', $policy->schedule_id)
                ->where('service_date', $date)->first();

            if ($existing !== null) {
                return $this->response($actor, $existing);
            }

            $id = (string) Str::uuid();
            DB::table('transport_reporting_obligations')->insert([
                'id' => $id, 'school_id' => $actor->school_id,
                'policy_id' => $policyId, 'service_date' => $date,
                'materialized_by' => $actor->getKey(),
            ]);
            $row = $this->obligation((string) $actor->school_id, $id);
            $this->audit->record(
                $actor, 'materialize', 'transport_reporting_obligations', $id, [], [
                    'policy_id' => $policyId, 'schedule_id' => $row->schedule_id,
                    'service_date' => $date, 'deadline_at' => $row->deadline_at,
                    'grace_until' => $row->grace_until,
                ]
            );

            return $this->response($actor, $row);
        });
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->response($actor, $this->obligation((string) $actor->school_id, $id));
    }

    private function obligation(string $school, string $id): object
    {
        $row = DB::table('transport_reporting_obligations')
            ->where('school_id', $school)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function response(User $actor, object $row): array
    {
        $runs = DB::table('transport_dated_assignments as a')
            ->leftJoin('transport_trips as t', function ($join): void {
                $join->on('t.school_id', '=', 'a.school_id')
                    ->on('t.assignment_id', '=', 'a.id');
            })
            ->where('a.school_id', $row->school_id)
            ->where('a.schedule_id', $row->schedule_id)
            ->where('a.service_date', $row->service_date)
            ->orderBy('a.id')
            ->select([
                'a.id', 'a.active', 'a.driver_id', 'a.attendant_id',
                't.id as trip_id',
            ])->get();

        $totals = [
            'planned_runs' => $runs->count(),
            'cancelled_runs' => 0,
            'uncaptured_runs' => 0,
            'captured_runs' => 0,
            'completed_runs' => 0,
            'incomplete_runs' => 0,
            'expected_journey_legs' => 0,
            'transported_legs' => 0,
            'unique_learners_transported' => 0,
            'awaiting_outcome' => 0,
            'on_board' => 0,
            'handed_over' => 0,
            'missed_pickup' => 0,
            'not_boarded' => 0,
            'cancelled' => 0,
            'unresolved_exceptions' => 0,
        ];
        $basis = [];
        $candidateIds = [];
        $tripIds = [];

        foreach ($runs as $run) {
            if ($run->active || $run->trip_id !== null) {
                $candidateIds[] = $run->driver_id;
                if ($run->attendant_id !== null) {
                    $candidateIds[] = $run->attendant_id;
                }
            }

            if ($run->trip_id === null) {
                if (! $run->active) {
                    $totals['cancelled_runs']++;
                    $basis[] = ['assignment_id' => $run->id, 'cancelled' => true];

                    continue;
                }

                $totals['uncaptured_runs']++;
                $reservations = DB::table('transport_learner_reservations')
                    ->where('school_id', $row->school_id)
                    ->where('assignment_id', $run->id)->where('active', true)
                    ->orderBy('id')->pluck('id')->all();
                $totals['expected_journey_legs'] += count($reservations);
                $totals['awaiting_outcome'] += count($reservations);
                $basis[] = [
                    'assignment_id' => $run->id, 'uncaptured' => true,
                    'reservation_ids' => $reservations,
                ];

                continue;
            }

            $tripIds[] = $run->trip_id;
            $totals['captured_runs']++;
            $snapshot = json_decode(DB::selectOne(
                'SELECT public.transport_trip_attendance_snapshot(?::uuid, ?::uuid) AS snapshot',
                [$row->school_id, $run->trip_id]
            )->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $completion = DB::table('transport_trip_completions')
                ->where('school_id', $row->school_id)->where('trip_id', $run->trip_id)
                ->orderByDesc('sequence')->first();

            if (($completion->status ?? null) === 'completed') {
                $totals['completed_runs']++;
            } else {
                $totals['incomplete_runs']++;
            }

            $totals['expected_journey_legs'] += $snapshot['planned_legs'];
            foreach ([
                'transported_legs', 'awaiting_outcome', 'on_board', 'handed_over',
                'missed_pickup', 'not_boarded', 'cancelled', 'unresolved_exceptions',
            ] as $key) {
                $totals[$key] += $snapshot[$key];
            }
            $basis[] = [
                'assignment_id' => $run->id, 'trip_id' => $run->trip_id,
                'completion_id' => $completion->id ?? null, 'attendance' => $snapshot,
            ];
        }

        if ($tripIds !== []) {
            $latest = DB::table('transport_journey_events')
                ->where('school_id', $row->school_id)->whereIn('trip_id', $tripIds)
                ->selectRaw('DISTINCT ON (roster_id) learner_id, state_after')
                ->orderBy('roster_id')->orderByDesc('sequence')->get();
            $totals['unique_learners_transported'] = $latest
                ->whereIn('state_after', ['on_board', 'at_school', 'at_destination'])
                ->pluck('learner_id')->unique()->count();
        }

        $responsible = [];
        foreach (array_unique($candidateIds) as $id) {
            $user = User::withoutGlobalScopes()->where('school_id', $row->school_id)
                ->whereKey($id)->first();
            if ($user === null || ! $user->active || $user->is_deleted
                || $user->suspended_at || $user->account_locked_until?->isFuture()) {
                continue;
            }
            $user->setRelation('school', $actor->school);
            if ($this->auth->hasPermission($user, 'submit_transport_daily_reports')) {
                $responsible[] = [
                    'id' => (string) $user->getKey(),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                ];
            }
        }

        $now = CarbonImmutable::now('UTC');
        $deadline = CarbonImmutable::parse($row->deadline_at);
        $grace = CarbonImmutable::parse($row->grace_until);

        return [
            'id' => (string) $row->id,
            'service_date' => $row->service_date,
            'timezone' => $row->timezone,
            'deadline_at' => $row->deadline_at,
            'grace_until' => $row->grace_until,
            'late_if_submitted_now' => $now->greaterThan($deadline),
            'overdue' => $now->greaterThan($grace),
            'responsibility_state' => $responsible === [] ? 'unassigned' : 'assigned',
            'responsible_staff' => $responsible,
            'policy' => json_decode($row->policy_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'schedule' => json_decode($row->schedule_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'totals' => $totals,
            'basis' => $basis,
        ];
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }

        return Validator::make($input, $rules)->validate();
    }

    private function write(callable $operation): array
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23505', '23P01'], true)) {
                abort(409, 'Reporting policy or obligation scope conflicts.');
            }
            if ($state === '23514') {
                abort(422, 'Reporting dates, weekdays or deadline are invalid.');
            }
            throw $exception;
        }
    }
}
