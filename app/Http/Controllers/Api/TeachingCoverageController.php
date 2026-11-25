<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use App\Services\Teaching\TeachingWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TeachingCoverageController extends BaseApiController
{
    public function school(Request $request, TeachingWorkspaceAccess $access)
    {
        return $this->success($this->coverage($access->school($request)));
    }

    public function ownClass(Request $request, TeacherPortalAccessService $access)
    {
        $teacher = $access->teacher($request->user());

        return $this->success($this->coverage((string) $teacher->school_id, (string) $teacher->id));
    }

    private function coverage(string $schoolId, ?string $classTeacherId = null): array
    {
        $today = today('Africa/Nairobi')->toDateString();
        $classes = DB::table('streams as stream')
            ->join('grades as grade', 'grade.id', '=', 'stream.grade_id')
            ->join('terms as term', 'term.school_id', '=', 'stream.school_id')
            ->join('academic_years as year', 'year.id', '=', 'term.academic_year_id')
            ->where('stream.school_id', $schoolId)->where('grade.school_id', $schoolId)->where('year.school_id', $schoolId)
            ->where('stream.active', true)->where('grade.active', true)->where('term.active', true)->where('year.active', true)
            ->whereDate('term.end_date', '>=', $today)->whereDate('year.end_date', '>=', $today)
            ->when($classTeacherId, function ($query) use ($classTeacherId, $today) {
                $query->whereExists(function ($appointments) use ($classTeacherId, $today) {
                    $appointments->selectRaw('1')->from('class_teachers as appointment')
                        ->whereColumn('appointment.school_id', 'stream.school_id')
                        ->whereColumn('appointment.stream_id', 'stream.id')->whereColumn('appointment.grade_id', 'grade.id')
                        ->whereColumn('appointment.term_id', 'term.id')->whereColumn('appointment.academic_year_id', 'year.id')
                        ->where('appointment.teacher_id', $classTeacherId)->where('appointment.active', true)
                        ->where(fn ($cover) => $cover->whereNull('appointment.temporary_until')
                            ->orWhere(fn ($temporary) => $temporary->whereDate('appointment.temporary_until', '>=', $today)
                                ->whereDate('term.start_date', '<=', $today)->whereDate('year.start_date', '<=', $today)));
                });
            })
            ->orderBy('term.start_date')->orderBy('grade.grade_order')->orderBy('stream.stream_name')->orderBy('stream.id')
            ->get(['grade.id as grade_id', 'grade.grade_name', 'stream.id as stream_id', 'stream.stream_name',
                'term.id as term_id', 'term.term_name', 'term.start_date', 'year.start_date as year_start', 'year.year_name']);

        if ($classes->isEmpty()) {
            return [];
        }

        $allocations = DB::table('learning_area_allocations as allocation')
            ->join('learning_areas as area', 'area.id', '=', 'allocation.learning_area_id')
            ->where('allocation.school_id', $schoolId)->where('allocation.active', true)->where('area.active', true)
            ->whereIn('allocation.grade_id', $classes->pluck('grade_id')->unique())
            ->orderBy('area.learning_area_name')
            ->get(['allocation.grade_id', 'area.id as area_id', 'area.learning_area_name', 'allocation.lessons_per_week'])
            ->groupBy('grade_id');

        $assignments = DB::table('teacher_assignments as assignment')
            ->join('teachers as teacher', 'teacher.id', '=', 'assignment.teacher_id')
            ->join('users as account', 'account.id', '=', 'teacher.user_id')
            ->join('terms as term', 'term.id', '=', 'assignment.term_id')
            ->whereColumn('assignment.academic_year_id', 'term.academic_year_id')
            ->where('assignment.school_id', $schoolId)->where('teacher.school_id', $schoolId)
            ->where('account.school_id', $schoolId)->where('term.school_id', $schoolId)
            ->where('assignment.active', true)->where('assignment.is_deleted', false)
            ->where('teacher.active', true)->where('teacher.is_deleted', false)
            ->where('account.active', true)->where('account.is_deleted', false)->whereNull('account.suspended_at')
            ->whereIn('assignment.grade_id', $classes->pluck('grade_id')->unique())
            ->whereIn('assignment.term_id', $classes->pluck('term_id')->unique())
            ->orderBy('account.first_name')->orderBy('account.last_name')
            ->get(['assignment.grade_id', 'assignment.stream_id', 'assignment.term_id', 'assignment.learning_area_id',
                'teacher.id as teacher_id', 'account.first_name', 'account.last_name'])
            ->groupBy(fn ($assignment) => $assignment->term_id.':'.$assignment->grade_id.':'.$assignment->learning_area_id);

        return $classes->map(function ($class) use ($allocations, $assignments, $today) {
            return [
                'id' => $class->term_id.':'.$class->stream_id,
                'grade' => $class->grade_name,
                'stream' => $class->stream_name,
                'year' => $class->year_name,
                'term' => $class->term_name,
                'upcoming' => $class->start_date > $today || $class->year_start > $today,
                'areas' => $allocations->get($class->grade_id, collect())->map(function ($area) use ($class, $assignments) {
                    $teachers = $assignments->get($class->term_id.':'.$class->grade_id.':'.$area->area_id, collect())
                        ->filter(fn ($assignment) => $assignment->stream_id === null || $assignment->stream_id === $class->stream_id)
                        ->unique('teacher_id')->map(fn ($assignment) => trim($assignment->first_name.' '.$assignment->last_name))->values();

                    return ['id' => $area->area_id, 'name' => $area->learning_area_name,
                        'lessons_per_week' => $area->lessons_per_week, 'teachers' => $teachers];
                })->values(),
            ];
        })->all();
    }
}
