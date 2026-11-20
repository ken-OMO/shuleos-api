<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SchoolReadinessSummaryService
{
    public function __construct(private SchoolSetupReadinessService $initialSetup) {}

    public function summary(string $schoolId): array
    {
        // This diagnostic does not change the admission gate or its five steps.
        $initial = $this->initialSetup->readiness($schoolId);
        $initialMissing = collect($initial['steps'])->filter(fn ($ready) => ! $ready)
            ->keys()->map(fn ($code) => ['code' => $code])->all();

        $grades = DB::table('grades')->where('school_id', $schoolId)->where('active', true);
        $years = DB::table('academic_years')->where('school_id', $schoolId)->where('active', true);
        $terms = DB::table('terms as term')
            ->join('academic_years as year', 'year.id', '=', 'term.academic_year_id')
            ->where('term.school_id', $schoolId)->where('year.school_id', $schoolId)
            ->where('term.active', true)->where('year.active', true);
        $streams = DB::table('streams as stream')
            ->join('grades as grade', 'grade.id', '=', 'stream.grade_id')
            ->where('stream.school_id', $schoolId)->where('grade.school_id', $schoolId)
            ->where('stream.active', true)->where('grade.active', true);
        $allocations = DB::table('learning_area_allocations as allocation')
            ->join('grades as grade', 'grade.id', '=', 'allocation.grade_id')
            ->join('learning_areas as area', 'area.id', '=', 'allocation.learning_area_id')
            ->where('allocation.school_id', $schoolId)->where('grade.school_id', $schoolId)
            ->where('allocation.active', true)->where('grade.active', true)->where('area.active', true);

        $academic = [];
        if (! (clone $years)->exists()) {
            $academic[] = ['code' => 'active_academic_year'];
        }
        if (! (clone $terms)->exists()) {
            $academic[] = ['code' => 'term_in_active_academic_year'];
        }
        if (! (clone $grades)->exists()) {
            $academic[] = ['code' => 'active_grades'];
        }
        $this->missingRows($academic, 'grades_without_streams', (clone $grades)
            ->whereNotIn('id', (clone $streams)->select('stream.grade_id')), ['id', 'grade_name']);
        $this->missingRows($academic, 'grades_without_learning_areas', (clone $grades)
            ->whereNotIn('id', (clone $allocations)->select('allocation.grade_id')), ['id', 'grade_name']);
        $this->missingRows($academic, 'terms_without_active_year', DB::table('terms')
            ->where('school_id', $schoolId)->where('active', true)
            ->whereNotIn('academic_year_id', (clone $years)->select('id')), ['id', 'term_name']);
        $this->missingRows($academic, 'streams_without_active_grade', DB::table('streams')
            ->where('school_id', $schoolId)->where('active', true)
            ->whereNotIn('grade_id', (clone $grades)->select('id')), ['id', 'stream_name']);
        $this->missingRows($academic, 'inactive_learning_area_allocations', DB::table('learning_area_allocations')
            ->where('school_id', $schoolId)->where('active', true)
            ->whereNotIn('id', (clone $allocations)->select('allocation.id')), ['id', 'grade_id', 'learning_area_id']);

        // Report malformed dates separately; active means configured, not "today".
        $this->missingRows($academic, 'academic_year_dates', (clone $years)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereNull('end_date')
                ->orWhereColumn('end_date', '<=', 'start_date')), ['id', 'year_name']);
        $this->missingRows($academic, 'term_dates', (clone $terms)
            ->where(fn ($q) => $q->whereNull('term.start_date')->orWhereNull('term.end_date')
                ->orWhereColumn('term.end_date', '<=', 'term.start_date')
                ->orWhereColumn('term.start_date', '<', 'year.start_date')
                ->orWhereColumn('term.end_date', '>', 'year.end_date')),
            ['term.id', 'term.term_name']);

        $teaching = [];
        if ($academic !== []) {
            $teaching[] = ['code' => 'academic_configuration_incomplete'];
        }
        $teachers = DB::table('teachers')->where('school_id', $schoolId)
            ->where('active', true)->where('is_deleted', false);
        if (! (clone $teachers)->exists()) {
            $teaching[] = ['code' => 'active_teachers'];
        }

        // Every active term is explicit in the diagnostic; do not pick an arbitrary first term.
        $targets = (clone $streams)->crossJoinSub((clone $terms)
            ->select('term.id as term_id', 'year.id as academic_year_id'), 'period');
        $validAssignments = DB::table('teacher_assignments as assignment')
            ->where('assignment.school_id', $schoolId)->where('assignment.active', true)
            ->where('assignment.is_deleted', false)
            ->whereIn('assignment.teacher_id', (clone $teachers)->select('id'))
            ->whereColumn('assignment.grade_id', 'grade.id')
            ->whereColumn('assignment.academic_year_id', 'period.academic_year_id')
            ->whereColumn('assignment.term_id', 'period.term_id');
        $subjects = (clone $targets)->joinSub((clone $allocations)
            ->select('allocation.grade_id', 'allocation.learning_area_id'), 'allocated',
            'allocated.grade_id', '=', 'grade.id');
        $subjectMatch = (clone $validAssignments)
            ->whereColumn('assignment.learning_area_id', 'allocated.learning_area_id')
            ->where(fn ($q) => $q->whereColumn('assignment.stream_id', 'stream.id')->orWhereNull('assignment.stream_id'));
        $this->missingRows($teaching, 'unassigned_learning_areas', $subjects->whereNotExists($subjectMatch),
            ['stream.id as stream_id', 'grade.id as grade_id', 'period.term_id', 'period.academic_year_id', 'allocated.learning_area_id']);
        $classMatch = (clone $validAssignments)->where('assignment.is_class_teacher', true)
            ->whereColumn('assignment.stream_id', 'stream.id')
            ->whereExists(DB::table('learning_area_allocations as class_allocation')
                ->join('learning_areas as class_area', 'class_area.id', '=', 'class_allocation.learning_area_id')
                ->where('class_allocation.school_id', $schoolId)->where('class_allocation.active', true)
                ->where('class_area.active', true)
                ->whereColumn('class_allocation.grade_id', 'assignment.grade_id')
                ->whereColumn('class_allocation.learning_area_id', 'assignment.learning_area_id'));
        $this->missingRows($teaching, 'streams_without_class_teachers', (clone $targets)->whereNotExists($classMatch),
            ['stream.id as stream_id', 'grade.id as grade_id', 'period.term_id', 'period.academic_year_id']);

        return [
            'school_id' => $schoolId,
            'initial_setup' => ['complete' => $initial['setup_complete'], 'steps' => $initial['steps'], 'missing' => $initialMissing],
            'academic_configuration' => ['complete' => $academic === [], 'missing' => $academic],
            'teaching_coverage' => ['complete' => $teaching === [], 'missing' => $teaching, 'period_basis' => 'all_active_terms_in_active_years'],
        ];
    }

    private function missingRows(array &$missing, string $code, Builder $query, array $columns): void
    {
        $count = (clone $query)->count();
        if ($count > 0) {
            foreach ($columns as $column) {
                $query->orderBy(explode(' as ', $column)[0]);
            }
            $missing[] = [
                'code' => $code,
                'count' => $count,
                'items' => $query->select($columns)
                    ->limit(50)->get()->map(fn ($row) => (array) $row)->all(),
                'truncated' => $count > 50,
            ];
        }
    }
}
