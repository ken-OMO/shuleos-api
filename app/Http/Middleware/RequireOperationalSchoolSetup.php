<?php

namespace App\Http\Middleware;

use App\Services\SchoolSetupReadinessService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOperationalSchoolSetup
{
    public function __construct(
        private SchoolSetupReadinessService $readiness
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || blank($user->school_id)) {
            return $this->schoolContextDenied();
        }

        $schoolId = (string) $user->school_id;

        $tenantSchoolId = $request->attributes->get('tenant_school_id');

        if (
            blank($tenantSchoolId)
            || (string) $tenantSchoolId !== $schoolId
        ) {
            return $this->schoolContextDenied();
        }

        if (! $this->readiness->isReady($schoolId)) {
            return response()->json([
                'success' => false,
                'message' => 'Initial school setup must be completed before this operation.',
            ], 403);
        }

        return $next($request);
    }

    private function schoolContextDenied(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'School context not found.',
        ], 403);
    }
}
