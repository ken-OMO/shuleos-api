<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Http\Request;

final class AssessmentTenantContext
{
    public static function schoolId(Request $request): string
    {
        $schoolId = auth()->user()?->school_id;
        abort_unless(filled($schoolId), 403, 'School context not found.');

        $tenant = $request->attributes->get('tenant_school_id');
        abort_if(
            $tenant !== null && (string) $tenant !== (string) $schoolId,
            403,
            'School context is invalid.'
        );

        return (string) $schoolId;
    }
}
