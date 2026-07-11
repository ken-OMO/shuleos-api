<?php

namespace App\Services\Teaching;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\LearningAreaAllocation;
use App\Models\SchemeOfWork;
use App\Models\Term;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchemeOfWorkService
{
    public function create(array $data, string $schoolId, ?string $userId): SchemeOfWork
    {
        $valid = Grade::whereKey($data['grade_id'])->where('school_id', $schoolId)->where('active', true)->exists()
            && AcademicYear::whereKey($data['academic_year_id'])->where('school_id', $schoolId)->where('active', true)->exists()
            && Term::whereKey($data['term_id'])->where('school_id', $schoolId)->where('academic_year_id', $data['academic_year_id'])->where('active', true)->exists()
            && LearningAreaAllocation::where('school_id', $schoolId)->where('grade_id', $data['grade_id'])->where('learning_area_id', $data['learning_area_id'])->where('active', true)->exists();

        if (!$valid) {
            throw ValidationException::withMessages(['scheme' => 'The scheme contains inactive, mismatched, or cross-school academic records.']);
        }

        $duplicate = SchemeOfWork::current()->where('school_id', $schoolId)
            ->where('learning_area_id', $data['learning_area_id'])->where('grade_id', $data['grade_id'])
            ->where('academic_year_id', $data['academic_year_id'])->where('term_id', $data['term_id'])->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['scheme' => 'A scheme already exists for this learning area, grade, year, and term.']);
        }

        return SchemeOfWork::create([
            ...$data, 'id' => (string) Str::uuid(), 'school_id' => $schoolId,
            'created_by' => $userId ?? $data['created_by'] ?? null,
            'active' => $data['active'] ?? true, 'is_deleted' => false, 'created_at' => now(),
        ]);
    }
}
