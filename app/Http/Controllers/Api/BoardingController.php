<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use Illuminate\Http\Request;

abstract class BoardingController extends BaseCrudController
{
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
