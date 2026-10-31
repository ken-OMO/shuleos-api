<?php

namespace App\Http\Middleware;

use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        // The learning-area catalogue is shared across schools.
        if ($prefix === 'learning-areas'
            && ! $request->isMethodSafe()
            && $user->school_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'School users cannot modify the shared learning-area catalogue.',
            ], 403);
        }

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
            $prefix === 'assessment-registrations'
                && ! $request->isMethodSafe() => 'manage_assessment_registrations',

            $prefix === 'mark-entry-permissions'
                && ! $request->isMethodSafe() => 'manage_mark_entry_permissions',

            $prefix === 'merit-lists'
                && $request->isMethod('POST')
                && $request->segment(3) === 'publish' => 'publish_merit_lists',

            $prefix === 'report-cards'
                && $request->isMethod('PATCH')
                && $request->segment(4) === 'comments' => 'update_report_card_comments',

            $prefix === 'report-cards'
                && $request->isMethod('POST')
                && $request->segment(3) === 'publish' => 'publish_report_cards',

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

        if ($assessmentRequest
            && $request->attributes->get('client_supplied_school_id') === true) {
            throw ValidationException::withMessages([
                'school_id' => ['The school_id field is prohibited.'],
            ]);
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
