<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class BoardingController extends BaseCrudController
{
    protected function audit(
        Request $request,
        string $module,
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $description = ''
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Boarding audit requires a mutation transaction.');
        }

        $schoolId = $this->schoolId($request);

        abort_unless((string) $model->getAttribute('school_id') === $schoolId, 403);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $this->userId($request),
            'module' => $module,
            'action' => $action,
            'table_name' => $model->getTable(),
            'record_id' => $model->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    protected function schoolId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->school_id, 403);

        $schoolId = (string) $user->school_id;

        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId !== null
            && (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403);
        }

        return $schoolId;
    }

    protected function userId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);

        return (string) $user->id;
    }
}
