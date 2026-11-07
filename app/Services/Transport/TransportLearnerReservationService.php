<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportLearnerReservationService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function create(User $authenticated, array $input): array
    {
        $this->rejectUnknown($input, ['assignment_id', 'learner_id', 'stop_id']);
        $values = Validator::make($input, [
            'assignment_id' => ['required', 'uuid'],
            'learner_id' => ['required', 'uuid'],
            'stop_id' => ['required', 'uuid'],
        ])->validate();

        return $this->write(function () use ($authenticated, $values): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport_enrolments');

            foreach ([
                ['transport_dated_assignments', $values['assignment_id']],
                ['learners', $values['learner_id']],
                ['transport_route_stops', $values['stop_id']],
            ] as [$table, $id]) {
                abort_unless(
                    DB::table($table)->where('school_id', $actor->school_id)
                        ->where('id', $id)->exists(),
                    404
                );
            }

            $id = (string) Str::uuid();
            DB::table('transport_learner_reservations')->insert($values + [
                'id' => $id,
                'school_id' => $actor->school_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $result = $this->read($actor, $id);
            $this->audit->record(
                $actor, 'create', 'transport_learner_reservations', $id, [], $result
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
            $this->access->requirePermission($actor, 'manage_transport_enrolments');

            $row = DB::table('transport_learner_reservations')
                ->where('school_id', $actor->school_id)->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $old = $this->response($row);
            abort_unless(hash_equals($old['version'], $values['expected_version']), 409);

            if (! $row->active) {
                return $old;
            }

            DB::table('transport_learner_reservations')->where('id', $id)->update([
                'active' => false, 'updated_by' => $actor->id,
            ]);

            $result = $this->read($actor, $id);
            $this->audit->record(
                $actor, 'end', 'transport_learner_reservations', $id,
                $old, $result + ['reason' => $reason]
            );

            return $result;
        });
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->read($actor, $id);
    }

    private function read(User $actor, string $id): array
    {
        $row = DB::table('transport_learner_reservations')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $this->response($row);
    }

    private function response(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'assignment_id' => (string) $row->assignment_id,
            'learner_id' => (string) $row->learner_id,
            'stop_id' => (string) $row->stop_id,
            'service_date' => (string) $row->service_date,
            'direction' => (string) $row->direction,
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
                abort(409, 'Capacity or learner journey reservation conflicts.');
            }
            if ($state === '23514') {
                abort(422, 'Learner, assignment or stop is unavailable for this reservation.');
            }

            throw $exception;
        }
    }
}
