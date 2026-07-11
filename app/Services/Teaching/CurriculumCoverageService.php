<?php

namespace App\Services\Teaching;

use App\Models\CurriculumCoverage;
use App\Models\RecordOfWork;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CurriculumCoverageService
{
    public function create(string $recordId, string $schoolId): CurriculumCoverage
    {
        $record = RecordOfWork::current()
            ->with(['lessonPlan.assignment', 'lessonPlan.schemeLesson.scheme', 'lessonPlan.schemeLesson.week'])
            ->whereKey($recordId)->where('school_id', $schoolId)->first();

        $plan = $record?->lessonPlan;
        $assignment = $plan?->assignment;
        $lesson = $plan?->schemeLesson;
        $scheme = $lesson?->scheme;
        $week = $lesson?->week;

        $consistent = $record && $plan && $assignment && $lesson && $scheme && $week
            && !$plan->is_deleted && !$assignment->is_deleted && !$lesson->is_deleted && !$scheme->is_deleted
            && $assignment->school_id === $schoolId && $scheme->school_id === $schoolId
            && $assignment->learning_area_id === $scheme->learning_area_id
            && $assignment->grade_id === $scheme->grade_id
            && $assignment->academic_year_id === $scheme->academic_year_id
            && $assignment->term_id === $scheme->term_id
            && $week->academic_year_id === $scheme->academic_year_id
            && $week->term_id === $scheme->term_id;

        if (!$consistent) {
            throw ValidationException::withMessages(['record_of_work_id' => 'The record does not resolve to a consistent curriculum chain.']);
        }

        if (CurriculumCoverage::where('record_of_work_id', $recordId)->exists()) {
            throw ValidationException::withMessages(['record_of_work_id' => 'Coverage already exists for this record of work.']);
        }

        return CurriculumCoverage::create([
            'id' => (string) Str::uuid(), 'school_id' => $schoolId,
            'teacher_assignment_id' => $assignment->id, 'scheme_id' => $scheme->id,
            'scheme_lesson_id' => $lesson->id, 'record_of_work_id' => $record->id,
            'date_completed' => $record->date_taught, 'strand' => $lesson->strand,
            'sub_strand' => $lesson->sub_strand, 'week_number' => $week->week_number,
            'completed' => $record->status === 'completed', 'is_deleted' => false, 'created_at' => now(),
        ]);
    }
}
