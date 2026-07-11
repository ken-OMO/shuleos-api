<?php

namespace App\Services\Teaching;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\LearningAreaAllocation;
use App\Models\Stream;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Term;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherAssignmentService
{
    public function create(array $data, string $schoolId): TeacherAssignment
    {
        $this->validateContext($data, $schoolId);
        $this->ensureAvailable($data, $schoolId);

        return TeacherAssignment::create([
            ...$data,
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'is_class_teacher' => $data['is_class_teacher'] ?? false,
            'active' => $data['active'] ?? true,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    public function update(TeacherAssignment $assignment, array $data): TeacherAssignment
    {
        if (($data['is_class_teacher'] ?? false) && !$assignment->is_class_teacher) {
            $this->ensureClassTeacherAvailable([
                ...$assignment->only(['stream_id', 'academic_year_id', 'term_id']),
                ...$data,
            ], $assignment->school_id, $assignment->id);
        }

        $assignment->update($data);

        return $assignment->refresh();
    }

    private function validateContext(array $data, string $schoolId): void
    {
        $valid = Teacher::whereKey($data['teacher_id'])->where('school_id', $schoolId)->where('active', true)->where('is_deleted', false)->exists()
            && Grade::whereKey($data['grade_id'])->where('school_id', $schoolId)->where('active', true)->exists()
            && AcademicYear::whereKey($data['academic_year_id'])->where('school_id', $schoolId)->where('active', true)->exists()
            && Term::whereKey($data['term_id'])->where('school_id', $schoolId)->where('academic_year_id', $data['academic_year_id'])->where('active', true)->exists()
            && LearningAreaAllocation::where('school_id', $schoolId)->where('grade_id', $data['grade_id'])->where('learning_area_id', $data['learning_area_id'])->where('active', true)->exists();

        if ($data['stream_id'] !== null) {
            $valid = $valid && Stream::whereKey($data['stream_id'])->where('school_id', $schoolId)->where('grade_id', $data['grade_id'])->where('active', true)->exists();
        }

        if (!$valid) {
            throw ValidationException::withMessages(['assignment' => 'The assignment contains inactive, mismatched, or cross-school records.']);
        }
    }

    private function ensureAvailable(array $data, string $schoolId): void
    {
        $duplicate = TeacherAssignment::current()
            ->where('school_id', $schoolId)
            ->where('teacher_id', $data['teacher_id'])
            ->where('learning_area_id', $data['learning_area_id'])
            ->where('grade_id', $data['grade_id'])
            ->where('stream_id', $data['stream_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('term_id', $data['term_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['assignment' => 'This teacher assignment already exists.']);
        }

        if ($data['is_class_teacher'] ?? false) {
            $this->ensureClassTeacherAvailable($data, $schoolId);
        }
    }

    private function ensureClassTeacherAvailable(array $data, string $schoolId, ?string $exceptId = null): void
    {
        if ($data['stream_id'] === null) {
            throw ValidationException::withMessages(['stream_id' => 'A stream is required for a class-teacher assignment.']);
        }

        $query = TeacherAssignment::current()->where('school_id', $schoolId)
            ->where('stream_id', $data['stream_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('term_id', $data['term_id'])
            ->where('is_class_teacher', true)->where('active', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['is_class_teacher' => 'This stream already has a class teacher for the selected term.']);
        }
    }
}
