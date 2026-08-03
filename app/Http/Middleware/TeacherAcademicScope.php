<?php

namespace App\Http\Middleware;

use App\Models\CurriculumCoverage;
use App\Models\LessonNote;
use App\Models\LessonPlan;
use App\Models\RecordOfWork;
use App\Models\SchemeOfWork;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TeacherAcademicScope
{
    public function __construct(private TeacherPortalAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $assignments = $this->access->assignments($user);
        $route = $request->route();

        if ($id = $route?->parameter('assignment')) {
            $this->access->requireAssignment($user, $id);
        }
        if ($id = $request->input('teacher_assignment_id')) {
            $this->access->requireAssignment($user, $id);
        }
        if ($id = $route?->parameter('stream')) {
            $this->access->requireStream($user, $id);
        }

        $schemeId = $route?->parameter('scheme') ?: $request->input('scheme_id');
        if ($route?->parameter('scheme') && $request->input('scheme_id') && $route->parameter('scheme') !== $request->input('scheme_id')) {
            abort(422, 'Route and payload scheme identifiers must match.');
        }
        if ($schemeId) {
            $this->requireScheme($user->school_id, $schemeId, $assignments);
        } elseif ($request->isMethod('post') && str_contains($request->path(), '/schemes')) {
            abort_unless($assignments->contains(fn ($a) => $a->learning_area_id === $request->input('learning_area_id') && $a->grade_id === $request->input('grade_id') && $a->academic_year_id === $request->input('academic_year_id') && $a->term_id === $request->input('term_id')), 403, 'Scheme is outside active teacher assignments.');
        }

        $planId = $route?->parameter('lessonPlan') ?: $request->input('lesson_plan_id');
        if ($planId) {
            $plan = LessonPlan::current()->whereKey($planId)->where('school_id', $user->school_id)->firstOrFail();
            $this->access->requireAssignment($user, $plan->teacher_assignment_id);
        }
        if ($noteId = $route?->parameter('lessonNote')) {
            $note = LessonNote::current()->whereKey($noteId)->where('school_id', $user->school_id)->with('lessonPlan')->firstOrFail();
            $this->access->requireAssignment($user, $note->lessonPlan->teacher_assignment_id);
        }
        if ($recordId = $route?->parameter('record')) {
            $record = RecordOfWork::current()->whereKey($recordId)->where('school_id', $user->school_id)->with('lessonPlan')->firstOrFail();
            $this->access->requireAssignment($user, $record->lessonPlan->teacher_assignment_id);
        }
        if ($coverageId = $route?->parameter('coverage')) {
            $coverage = CurriculumCoverage::current()->whereKey($coverageId)->where('school_id', $user->school_id)->firstOrFail();
            $this->access->requireAssignment($user, $coverage->teacher_assignment_id);
        }

        if (! $request->isMethodSafe()) {
            foreach (['scheme' => 'scheme_of_work', 'lessonPlan' => 'lesson_plan', 'lessonNote' => 'lesson_note', 'record' => 'record_of_work'] as $parameter => $type) {
                $entityId = $route?->parameter($parameter);
                if (! $entityId || str_ends_with($request->path(), '/submit') || str_ends_with($request->path(), '/withdraw')) {
                    continue;
                }
                $state = DB::table('teacher_workflows')->where('school_id', $user->school_id)->where('entity_type', $type)->where('entity_id', $entityId)->value('state');
                abort_if($state && ! in_array($state, ['draft', 'changes_requested', 'rejected'], true), 409, 'Submitted or approved teaching work is immutable.');
            }
        }

        return $next($request);
    }

    private function requireScheme(string $schoolId, string $id, $assignments): SchemeOfWork
    {
        $scheme = SchemeOfWork::current()->whereKey($id)->where('school_id', $schoolId)->firstOrFail();
        abort_unless($assignments->contains(fn ($a) => $a->learning_area_id === $scheme->learning_area_id && $a->grade_id === $scheme->grade_id && $a->academic_year_id === $scheme->academic_year_id && $a->term_id === $scheme->term_id), 403, 'Scheme is outside active teacher assignments.');

        return $scheme;
    }
}
