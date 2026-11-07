<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TransportFoundationService
{
    private const TABLES = [
        'vehicle' => 'transport_vehicles',
        'route' => 'transport_routes',
        'stop' => 'transport_route_stops',
    ];

    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function show(User $authenticated, string $kind, string $id): array
    {
        $table = $this->table($kind);
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        $row = DB::table($table)->where('school_id', $actor->school_id)
            ->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $this->response($kind, $row);
    }

    public function create(User $authenticated, string $kind, array $input): array
    {
        $table = $this->table($kind);

        return $this->write(function () use ($authenticated, $kind, $table, $input): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $values = $this->validate($kind, $input, false);

            if ($kind === 'stop') {
                $this->activeRoute($actor, $values['route_id']);
            }

            $id = (string) Str::uuid();
            DB::table($table)->insert($values + [
                'id' => $id,
                'school_id' => $actor->school_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $row = DB::table($table)->where('id', $id)->first();
            abort_unless($row !== null, 404);
            $result = $this->response($kind, $row);
            $this->audit->record($actor, 'create', $table, $id, [], $result);

            return $result;
        });
    }

    public function update(
        User $authenticated,
        string $kind,
        string $id,
        array $input
    ): array {
        $table = $this->table($kind);

        return $this->write(function () use (
            $authenticated, $kind, $table, $id, $input
        ): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $values = $this->validate($kind, $input, true);
            $expected = $values['expected_version'];
            unset($values['expected_version']);

            $row = DB::table($table)->where('school_id', $actor->school_id)
                ->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);

            $old = $this->response($kind, $row);
            abort_unless(
                hash_equals($old['version'], $expected),
                409,
                'Transport resource changed. Reload before updating.'
            );

            // Identity fields are deliberately unavailable in update input.
            $changes = [];
            foreach ($values as $field => $value) {
                if ($old[$field] !== $value) {
                    $changes[$field] = $value;
                }
            }

            if ($changes === []) {
                return $old;
            }

            if ($kind === 'stop' && ($changes['active'] ?? $old['active'])) {
                $this->activeRoute($actor, (string) $row->route_id);
            }

            DB::table($table)->where('school_id', $actor->school_id)
                ->where('id', $id)
                ->update($changes + ['updated_by' => $actor->id]);

            $updated = DB::table($table)->where('id', $id)->first();
            abort_unless($updated !== null, 404);
            $result = $this->response($kind, $updated);
            $this->audit->record($actor, 'update', $table, $id, $old, $result);

            return $result;
        });
    }

    private function activeRoute(User $actor, string $id): void
    {
        $route = DB::table('transport_routes')
            ->where('school_id', $actor->school_id)
            ->where('id', $id)->lockForUpdate()->first();
        abort_unless($route !== null, 404);
        abort_unless($route->active, 422, 'Route is retired.');
    }

    private function table(string $kind): string
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return self::TABLES[$kind];
    }

    private function validate(string $kind, array $input, bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';
        $rules = match ($kind) {
            'vehicle' => [
                'registration_number' => [$required, 'string', 'max:32'],
                'ownership_type' => [$required, Rule::in(['school_owned', 'contracted'])],
                'learner_capacity' => [$required, 'integer', 'min:1', 'max:2147483647'],
            ],
            'route' => [
                'route_code' => [$required, 'string', 'max:40'],
                'route_name' => [$required, 'string', 'max:150'],
            ],
            'stop' => [
                'stop_name' => [$required, 'string', 'max:150'],
                'stop_order' => [$required, 'integer', 'min:1', 'max:2147483647'],
            ],
            default => throw new NotFoundHttpException,
        };

        if ($kind === 'stop' && ! $update) {
            $rules['route_id'] = ['required', 'uuid'];
            $rules['direction'] = ['required', Rule::in(['to_school', 'from_school'])];
        }

        $rules['active'] = ['sometimes', 'boolean'];
        if ($update) {
            $rules['expected_version'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }

        foreach (array_keys($input) as $field) {
            if (! array_key_exists($field, $rules)) {
                throw ValidationException::withMessages([
                    $field => 'This field is unavailable for this operation.',
                ]);
            }
        }

        foreach (['registration_number', 'route_code', 'route_name', 'stop_name'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        $values = Validator::make($input, $rules)->validate();

        foreach (['registration_number', 'route_code', 'route_name', 'stop_name'] as $field) {
            if (isset($values[$field]) && $values[$field] === '') {
                throw ValidationException::withMessages([$field => 'A value is required.']);
            }
        }

        foreach (['registration_number', 'route_code'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = Str::upper($values[$field]);
            }
        }
        foreach (['learner_capacity', 'stop_order'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = (int) $values[$field];
            }
        }
        if (array_key_exists('active', $values)) {
            $values['active'] = (bool) $values['active'];
        }

        return $values;
    }

    private function response(string $kind, object $row): array
    {
        $fields = match ($kind) {
            'vehicle' => ['registration_number', 'ownership_type', 'learner_capacity'],
            'route' => ['route_code', 'route_name'],
            'stop' => ['route_id', 'direction', 'stop_name', 'stop_order'],
            default => throw new NotFoundHttpException,
        };

        $result = ['id' => (string) $row->id];
        foreach ($fields as $field) {
            $result[$field] = in_array($field, ['learner_capacity', 'stop_order'], true)
                ? (int) $row->{$field}
                : (string) $row->{$field};
        }
        $result['active'] = (bool) $row->active;
        $result['version'] = hash('sha256', json_encode([
            $kind, (string) $row->id, (string) $row->lock_version,
        ], JSON_THROW_ON_ERROR));

        return $result;
    }

    private function write(callable $operation): array
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            // The transaction has rolled back before translating a conflict.
            if (($exception->errorInfo[0] ?? null) === '23505') {
                abort(409, 'Transport code, registration or active stop order already exists.');
            }

            throw $exception;
        }
    }
}
