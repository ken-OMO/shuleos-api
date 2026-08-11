<?php

namespace App\Services\Assessment;

use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\Exam;
use App\Models\Term;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamService
{
    public function create(array $d, string $school, ?string $user): Exam
    {
        $type = AssessmentType::current()->whereKey($d['assessment_type_id'])->where('school_id', $school)->where('active', true)->exists();
        $year = AcademicYear::whereKey($d['academic_year_id'])->where('school_id', $school)->where('active', true)->exists();
        $term = Term::whereKey($d['term_id'])->where('school_id', $school)->where('academic_year_id', $d['academic_year_id'])->where('active', true)->first();
        if (! $type || ! $year || ! $term) {
            throw ValidationException::withMessages(['exam' => 'The assessment type or academic period is inactive, mismatched, or outside this school.']);
        }$start = Carbon::parse($d['start_date']);
        $end = Carbon::parse($d['end_date']);
        if ($end->lt($start) || $start->lt($term->start_date) || $end->gt($term->end_date)) {
            throw ValidationException::withMessages(['start_date' => 'Exam dates must fall within the selected term.']);
        }if (Exam::current()->where('school_id', $school)->where('academic_year_id', $d['academic_year_id'])->where('term_id', $d['term_id'])->whereRaw('LOWER(exam_name) = ?', [mb_strtolower(trim($d['exam_name']))])->exists()) {
            throw ValidationException::withMessages(['exam_name' => 'An exam with this name already exists in the selected term.']);
        }

        return Exam::create([...$d, 'id' => (string) Str::uuid(), 'school_id' => $school, 'exam_name' => trim($d['exam_name']), 'active' => $d['active'] ?? true, 'status' => 'draft', 'created_by' => $user ?? $d['created_by'] ?? null, 'is_deleted' => false, 'created_at' => now()]);
    }

    public function transition(Exam $e, string $to): void
    {
        $allowed = ['draft' => ['published'], 'published' => ['closed'], 'closed' => []];
        if (! in_array($to, $allowed[$e->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Cannot change exam status from {$e->status} to {$to}."]);
        }$e->update(['status' => $to]);
    }
}
