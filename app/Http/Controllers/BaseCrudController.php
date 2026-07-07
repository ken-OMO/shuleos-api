<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class BaseCrudController extends BaseApiController
{
    /**
     * Write audit trail.
     */
    protected function audit(
        Request $request,
        string $module,
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $description = ''
    ): void {

        AuditLogger::log(

            request: $request,

            module: $module,

            action: $action,

            model: $model,

            oldValues: $oldValues,

            newValues: $newValues,

            description: $description

        );

    }
}
