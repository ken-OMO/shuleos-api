<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoardingOccurrenceCategoryService
{
    public function __construct(
        private BoardingReportingAccessService $access,
        private BoardingReportingAuditService $audit,
        private AuthContextService $auth,
    ) {}

    public function read(User $user, int $page = 1, int $perPage = 50): array
    {
        $actor = $this->access->actor($user);
        abort_unless(
            $this->access->schoolWideReader($actor)
            || $this->auth->hasPermission($actor, 'configure_boarding_reporting')
            || $this->auth->hasPermission($actor, 'record_boarding_operations'),
            403
        );
        abort_unless($page >= 1 && $page <= 100000 && $perPage >= 1 && $perPage <= 100, 422);

        $query = DB::table('boarding_occurrence_categories')
            ->where('school_id', $actor->school_id);
        $total = (clone $query)->count();
        $rows = $query->orderBy('category_name')->orderBy('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['id', 'category_name', 'active', 'lock_version']);

        return [
            'data' => $rows->map(fn ($row): array => $this->resource($row))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function create(User $user, array $data): array
    {
        return $this->transaction(function () use ($user, $data): array {
            $actor = $this->writer($user);
            $values = $this->values($data);
            $id = (string) Str::uuid();

            DB::table('boarding_occurrence_categories')->insert([
                'id' => $id,
                'school_id' => $actor->school_id,
                'category_name' => $values['category_name'],
                'active' => $values['active'],
                'created_by' => $actor->id,
            ]);

            $row = DB::table('boarding_occurrence_categories')
                ->where('school_id', $actor->school_id)->where('id', $id)->first();

            $result = $this->resource($row);
            $this->audit->record(
                $actor, 'occurrence_category_created',
                'boarding_occurrence_categories', $id, [], $result
            );

            return $result;
        });
    }

    public function update(User $user, string $id, string $expectedVersion, array $data): array
    {
        return $this->transaction(function () use ($user, $id, $expectedVersion, $data): array {
            $actor = $this->writer($user);
            $row = DB::table('boarding_occurrence_categories')
                ->where('school_id', $actor->school_id)->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row !== null, 404);

            $old = $this->resource($row);
            abort_unless(hash_equals($old['version'], $expectedVersion), 409);
            $values = $this->values($data);

            if ($values['category_name'] === $row->category_name
                && $values['active'] === (bool) $row->active) {
                return $old;
            }

            DB::table('boarding_occurrence_categories')
                ->where('school_id', $actor->school_id)->where('id', $id)
                ->update($values);

            $row = DB::table('boarding_occurrence_categories')
                ->where('school_id', $actor->school_id)->where('id', $id)->first();
            $result = $this->resource($row);
            $this->audit->record(
                $actor, 'occurrence_category_updated',
                'boarding_occurrence_categories', $id, $old, $result
            );

            return $result;
        });
    }

    private function writer(User $user): User
    {
        $actor = $this->access->actor($user, true);
        $this->access->requirePermission($actor, 'configure_boarding_reporting');

        return $actor;
    }

    private function values(array $data): array
    {
        if (isset($data['category_name']) && is_string($data['category_name'])) {
            $data['category_name'] = trim($data['category_name']);
        }

        $validated = validator($data, [
            'category_name' => ['required', 'string', 'max:100'],
            'active' => ['required', 'boolean'],
            'school_id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ])->validate();

        return [
            'category_name' => $validated['category_name'],
            'active' => (bool) $validated['active'],
        ];
    }

    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'category_name' => $row->category_name,
            'active' => (bool) $row->active,
            'version' => hash('sha256', json_encode(
                [(string) $row->id, (string) $row->lock_version, $row->category_name, (bool) $row->active],
                JSON_THROW_ON_ERROR
            )),
        ];
    }

    private function transaction(Closure $operation): array
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'boarding_occurrence_category_name_key')) {
                throw ValidationException::withMessages([
                    'category_name' => 'An occurrence category with this name already exists.',
                ]);
            }

            throw $exception;
        }
    }
}
