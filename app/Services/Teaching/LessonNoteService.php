<?php

namespace App\Services\Teaching;

use App\Models\LessonNote;
use App\Models\LessonPlan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LessonNoteService
{
    public function create(array $d, string $school, ?string $user): LessonNote
    {
        $plan = LessonPlan::current()->whereKey($d['lesson_plan_id'])->where('school_id', $school)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['lesson_plan_id' => 'The lesson plan is unavailable or outside this school.']);
        }if (LessonNote::where('lesson_plan_id', $plan->id)->exists()) {
            throw ValidationException::withMessages(['lesson_plan_id' => 'A lesson note already exists for this lesson plan.']);
        }

        return LessonNote::create([...$d, 'id' => (string) Str::uuid(), 'school_id' => $school, 'created_by' => $user ?? $d['created_by'] ?? null, 'is_deleted' => false, 'created_at' => now()]);
    }
}
