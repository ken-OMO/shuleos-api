<?php
namespace App\Services\Teaching;
use App\Models\AcademicWeek;
use App\Models\SchemeLesson;
use App\Models\SchemeOfWork;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class SchemeLessonService
{
    public function create(array $data, string $schoolId): SchemeLesson
    {
        $scheme = SchemeOfWork::current()->whereKey($data['scheme_id'])->where('school_id', $schoolId)->where('active', true)->first();
        if (!$scheme || !AcademicWeek::whereKey($data['week_id'])->where('school_id', $schoolId)
            ->where('academic_year_id', $scheme?->academic_year_id)->where('term_id', $scheme?->term_id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['lesson' => 'The scheme and academic week are inactive, mismatched, or outside this school.']);
        }
        if (SchemeLesson::where('scheme_id', $data['scheme_id'])->where('lesson_number', $data['lesson_number'])->exists()) {
            throw ValidationException::withMessages(['lesson_number' => 'This lesson number already exists in the scheme.']);
        }
        return SchemeLesson::create([...$data, 'id' => (string) Str::uuid(), 'is_deleted' => false, 'created_at' => now()]);
    }
}
