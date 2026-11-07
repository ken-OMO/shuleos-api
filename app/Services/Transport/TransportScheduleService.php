<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportScheduleService
{
    public function __construct(private TransportAccessService $access, private TransportAuditService $audit) {}

    public function create(User $authenticated, array $input): array
    {
        $data = $this->validate($input, [
            'route_id' => ['required', 'uuid'], 'service_code' => ['required', 'string', 'max:40'],
            'direction' => ['required', 'in:to_school,from_school'], 'timezone' => ['required', 'timezone'],
            'weekday_mask' => ['required', 'integer', 'between:1,127'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'departure_time' => ['required', 'date_format:H:i'], 'completion_time' => ['required', 'date_format:H:i'],
            'completion_day_offset' => ['sometimes', 'integer', 'in:0,1'],
            'turnaround_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ]);
        $data['service_code'] = strtoupper(trim($data['service_code']));
        if ($data['service_code'] === '') {
            throw ValidationException::withMessages(['service_code' => ['A service code is required.']]);
        }

        return $this->write(function () use ($authenticated, $data): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $route = DB::table('transport_routes')->where('school_id', $actor->school_id)->where('id', $data['route_id'])->sharedLock()->first();
            abort_unless($route !== null, 404);
            abort_unless($route->active, 422, 'Route is retired.');
            $id = (string) Str::uuid();
            DB::table('transport_service_schedules')->insert([
                ...$data, 'id' => $id, 'school_id' => $actor->school_id,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $result = $this->response($this->row($actor, $id));
            $this->audit->record($actor, 'create', 'transport_service_schedules', $id, [], $result);

            return $result;
        });
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->response($this->row($actor, $id));
    }

    public function updateState(User $authenticated, string $id, array $input): array
    {
        $data = $this->validate($input, [
            'expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        return $this->write(function () use ($authenticated, $id, $data): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $old = $this->response($this->row($actor, $id, true));
            abort_unless(hash_equals($old['version'], $data['expected_version']), 409);
            if ($old['active'] === (bool) $data['active']) {
                return $old;
            }
            if ($data['active']) {
                $route = DB::table('transport_routes')->where('school_id', $actor->school_id)->where('id', $old['route_id'])->sharedLock()->first();
                abort_unless($route !== null && $route->active, 422, 'Route is retired.');
            }
            DB::table('transport_service_schedules')->where('school_id', $actor->school_id)->where('id', $id)->update([
                'active' => (bool) $data['active'], 'updated_by' => $actor->getKey(),
            ]);
            $result = $this->response($this->row($actor, $id));
            $this->audit->record($actor, 'update_state', 'transport_service_schedules', $id, $old, $result + ['reason' => $data['reason']]);

            return $result;
        });
    }

    private function row(User $actor, string $id, bool $lock = false): object
    {
        $query = DB::table('transport_service_schedules')->where('school_id', $actor->school_id)->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function response(object $row): array
    {
        return [
            'id' => (string) $row->id, 'route_id' => (string) $row->route_id,
            'service_code' => $row->service_code, 'direction' => $row->direction, 'timezone' => $row->timezone,
            'weekday_mask' => (int) $row->weekday_mask, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to,
            'departure_time' => $row->departure_time, 'completion_time' => $row->completion_time,
            'completion_day_offset' => (int) $row->completion_day_offset, 'turnaround_minutes' => (int) $row->turnaround_minutes,
            'active' => (bool) $row->active,
            'version' => hash('sha256', 'transport-schedule:'.$row->id.':'.$row->lock_version),
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
            if (in_array($exception->errorInfo[0] ?? null, ['23505', '23P01'], true)) {
                abort(409, 'Schedule code or current state conflicts.');
            }
            if (($exception->errorInfo[0] ?? null) === '23514') {
                abort(422, 'Schedule dates, timetable or resource state are invalid.');
            }
            throw $exception;
        }
    }
}
