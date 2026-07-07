<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /**
     * Record an audit log.
     */
    public static function log(
        Request $request,
        string $module,
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): void {

        try {

            AuditLog::create([

                'school_id' => auth()->user()?->school_id,

                'user_id' => auth()->id(),

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

        } catch (\Throwable $e) {

            Log::error('Audit logging failed.', [

                'module' => $module,

                'action' => $action,

                'record_id' => $model->getKey(),

                'error' => $e->getMessage(),

            ]);

        }
    }
}
