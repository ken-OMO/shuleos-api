<?php

namespace App\Services\Teaching;

use App\Models\LessonPlan;
use App\Models\SchemeLesson;
use App\Models\TeacherAssignment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LessonPlanService
{
    public function create(array $d, string $school, ?string $user): LessonPlan
    {
        $a = TeacherAssignment::current()->whereKey($d['teacher_assignment_id'])->where('school_id', $school)->where('active', true)->first();
        $l = SchemeLesson::current()->with('scheme')->find($d['scheme_lesson_id']);
        $s = $l?->scheme;
        if (! $a || ! $s || $s->is_deleted || ! $s->active || $s->school_id !== $school || $a->learning_area_id !== $s->learning_area_id || $a->grade_id !== $s->grade_id || $a->academic_year_id !== $s->academic_year_id || $a->term_id !== $s->term_id) {
            throw ValidationException::withMessages(['plan' => 'The teacher assignment and scheme lesson do not share the same active academic context.']);
        }
        if (LessonPlan::where('teacher_assignment_id', $a->id)->where('scheme_lesson_id', $l->id)->exists()) {
            throw ValidationException::withMessages(['plan' => 'A lesson plan already exists for this assignment and scheme lesson.']);
        }

        return LessonPlan::create([...$d, 'id' => (string) Str::uuid(), 'school_id' => $school, 'created_by' => $user ?? $d['created_by'] ?? null, 'status' => $d['status'] ?? 'draft', 'is_deleted' => false, 'created_at' => now()]);
    }

    public function transition(LessonPlan $p, string $to): void
    {
        $allowed = ['draft' => ['submitted'], 'submitted' => ['approved', 'rejected'], 'rejected' => ['draft'], 'approved' => []];
        if (! in_array($to, $allowed[$p->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Cannot change lesson plan status from {$p->status} to {$to}."]);
        }$p->update(['status' => $to]);
    }
}
