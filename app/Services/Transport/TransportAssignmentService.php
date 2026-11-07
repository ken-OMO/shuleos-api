<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportAssignmentService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function create(User $authenticated, array $input): array
    {
        $allowed = [
            'schedule_id', 'service_date', 'run_number',
            'vehicle_id', 'driver_id', 'attendant_id',
        ];
        $this->rejectUnknown($input, $allowed);

        $values = Validator::make($input, [
            'schedule_id' => ['required', 'uuid'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'run_number' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'vehicle_id' => ['required', 'uuid'],
            'driver_id' => ['required', 'uuid'],
            'attendant_id' => ['nullable', 'uuid', 'different:driver_id'],
        ])->validate();

        return $this->write(function () use ($authenticated, $values): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');

            foreach ([
                ['transport_service_schedules', $values['schedule_id']],
                ['transport_vehicles', $values['vehicle_id']],
                ['users', $values['driver_id']],
                ['users', $values['attendant_id'] ?? null],
            ] as [$table, $id]) {
                if ($id !== null) {
                    abort_unless(
                        DB::table($table)->where('school_id', $actor->school_id)
                            ->where('id', $id)->exists(),
                        404
                    );
                }
            }

            $id = (string) Str::uuid();
            DB::table('transport_dated_assignments')->insert($values + [
                'id' => $id,
                'school_id' => $actor->school_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $row = DB::table('transport_dated_assignments')->where('id', $id)->first();
            abort_unless($row !== null, 404);
            $result = $this->response($row);
            $this->audit->record(
                $actor, 'create', 'transport_dated_assignments', $id, [], $result
            );

            return $result;
        });
    }

    public function end(User $authenticated, string $id, array $input): array
    {
        $this->rejectUnknown($input, ['expected_version', 'reason']);
        $values = Validator::make($input, [
            'expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $reason = trim($values['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return $this->write(function () use ($authenticated, $id, $values, $reason): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $row = DB::table('transport_dated_assignments')
                ->where('school_id', $actor->school_id)->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $old = $this->response($row);
            abort_unless(hash_equals($old['version'], $values['expected_version']), 409);

            if (! $row->active) {
                return $old;
            }

            DB::table('transport_dated_assignments')->where('id', $id)->update([
                'active' => false, 'updated_by' => $actor->id,
            ]);

            $updated = DB::table('transport_dated_assignments')->where('id', $id)->first();
            abort_unless($updated !== null, 404);
            $result = $this->response($updated);
            $this->audit->record(
                $actor, 'end', 'transport_dated_assignments', $id,
                $old, $result + ['reason' => $reason]
            );

            return $result;
        });
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');
        $row = DB::table('transport_dated_assignments')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $this->response($row);
    }

    private function response(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'schedule_id' => (string) $row->schedule_id,
            'service_date' => (string) $row->service_date,
            'run_number' => (int) $row->run_number,
            'vehicle_id' => (string) $row->vehicle_id,
            'driver_id' => (string) $row->driver_id,
            'attendant_id' => $row->attendant_id,
            'starts_at' => $row->starts_at,
            'completes_at' => $row->completes_at,
            'reserved_until' => $row->reserved_until,
            'active' => (bool) $row->active,
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
            $state = $exception->errorInfo[0] ?? null;

            if (in_array($state, ['23P01', '23505'], true)) {
                abort(409, 'Vehicle, staff or service run is already reserved.');
            }
            if ($state === '23514') {
                abort(422, 'Assignment date, resources or lifecycle is unavailable.');
            }

            throw $exception;
        }
    }
}
