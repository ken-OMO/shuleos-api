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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TransportTripService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function capture(User $authenticated, array $input): array
    {
        foreach (array_keys($input) as $field) {
            if ($field !== 'assignment_id') {
                throw ValidationException::withMessages([$field => 'Unavailable field.']);
            }
        }

        $values = Validator::make($input, [
            'assignment_id' => ['required', 'uuid'],
        ])->validate();

        try {
            return DB::transaction(function () use ($authenticated, $values): array {
                $actor = $this->access->actor($authenticated, true);
                $this->access->requirePermission($actor, 'record_transport_journeys');

                $assignment = DB::table('transport_dated_assignments')
                    ->where('school_id', $actor->school_id)
                    ->where('id', $values['assignment_id'])->lockForUpdate()->first();
                abort_unless($assignment !== null, 404);
                $this->requireResponsibility($actor, $assignment);

                $existing = DB::table('transport_trips')
                    ->where('school_id', $actor->school_id)
                    ->where('assignment_id', $assignment->id)->first();

                if ($existing !== null) {
                    return $this->response($existing);
                }

                $schedule = DB::table('transport_service_schedules')
                    ->where('school_id', $actor->school_id)
                    ->where('id', $assignment->schedule_id)->first();
                abort_unless($schedule !== null, 404);
                abort_unless(
                    $assignment->service_date === CarbonImmutable::now(
                        $schedule->timezone
                    )->toDateString(),
                    422,
                    'Capture is available on the service-local operating date.'
                );

                $id = (string) Str::uuid();
                DB::table('transport_trips')->insert([
                    'id' => $id,
                    'school_id' => $actor->school_id,
                    'assignment_id' => $assignment->id,
                    'captured_by' => $actor->id,
                ]);

                $trip = DB::table('transport_trips')->where('id', $id)->first();
                abort_unless($trip !== null, 404);
                $result = $this->response($trip);
                $this->audit->record(
                    $actor, 'capture', 'transport_trips', $id, [],
                    ['assignment_id' => $assignment->id, 'roster_count' => $trip->roster_count]
                );

                return $result;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23514') {
                abort(422, 'Resolve unavailable assignment resources before capture.');
            }

            throw $exception;
        }
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $trip = DB::table('transport_trips')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($trip !== null, 404);

        try {
            $this->access->requirePermission($actor, 'view_transport_operations');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            $this->access->requirePermission($actor, 'record_transport_journeys');
            $this->requireResponsibility($actor, $trip);
        }

        return $this->response($trip);
    }

    private function requireResponsibility(User $actor, object $row): void
    {
        abort_unless(
            (string) $actor->id === (string) $row->driver_id
            || ($row->attendant_id !== null
                && (string) $actor->id === (string) $row->attendant_id),
            403
        );
    }

    private function response(object $trip): array
    {
        $roster = DB::table('transport_trip_roster')
            ->where('school_id', $trip->school_id)->where('trip_id', $trip->id)
            ->orderBy('learner_id')->get();

        return [
            'id' => (string) $trip->id,
            'assignment_id' => (string) $trip->assignment_id,
            'service_date' => (string) $trip->service_date,
            'direction' => (string) $trip->direction,
            'captured_at' => $trip->captured_at,
            'context' => json_decode($trip->context_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'staff' => json_decode($trip->staff_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'roster_count' => (int) $trip->roster_count,
            'roster' => $roster->map(fn ($row) => [
                'id' => (string) $row->id,
                'reservation_id' => (string) $row->reservation_id,
                'learner' => json_decode($row->learner_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'stop' => json_decode($row->stop_snapshot, true, 512, JSON_THROW_ON_ERROR),
            ])->all(),
        ];
    }
}
