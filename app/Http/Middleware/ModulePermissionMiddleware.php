<?php

namespace App\Http\Middleware;

use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ModulePermissionMiddleware
{
    public function __construct(private AuthContextService $authContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $prefix = $request->segment(2);

        $assessmentRequest = in_array($prefix, [
            'assessment-reissues',
            'assessment-registrations',
            'assessment-types',
            'exams',
            'exam-learning-areas',
            'exam-papers',
            'exam-results',
            'learning-area-results',
            'mark-entry-permissions',
            'merit-lists',
            'report-cards',
        ], true) || ($prefix === 'teacher' && (
            $request->segment(3) === 'marks-entry'
            || ($request->segment(3) === 'moderation'
                && $request->segment(4) === 'marks')
        ));

        if ($assessmentRequest
            && ! $request->isMethodSafe()
            && $user->school()->value('lifecycle_state') === 'read_only') {
            return response()->json([
                'success' => false,
                'message' => 'Assessment changes are unavailable while the school is read-only.',
            ], 403);
        }

        $required = match (true) {
            $prefix === 'learners'
                && $request->segment(4) === 'guardians' => config('module_permissions.guardians'),

            default => config("module_permissions.{$prefix}"),
        };

        if (! $required) {
            return response()->json(['success' => false, 'message' => 'No permission policy is configured for this module.'], 403);
        }

        try {
            if (! $this->authContext->hasPermission($user, $required)) {
                return response()->json(['success' => false, 'message' => 'Permission denied.'], 403);
            }
        } catch (AuthenticationException) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        } catch (AuthorizationException) {
            return response()->json(['success' => false, 'message' => 'Access is unavailable.'], 403);
        }

        if ($assessmentRequest && ! $request->isMethodSafe()) {
            return DB::transaction(
                function () use ($user, $request, $next): Response {
                    app(ExamResultLifecycleService::class)
                        ->assertSchoolWritable((string) $user->school_id);

                    return $next($request);
                }
            );
        }

        return $next($request);
    }
}
