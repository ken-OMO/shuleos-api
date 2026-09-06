<?php

namespace App\Services\Teaching;

use App\Models\LessonPlan;
use App\Models\RecordOfWork;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordOfWorkService
{
    public function create(array $d, string $school, ?string $user): RecordOfWork
    {
        $plan = LessonPlan::current()->whereKey($d['lesson_plan_id'])->where('school_id', $school)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['lesson_plan_id' => 'The lesson plan is unavailable or outside this school.']);
        }if ($plan->status !== 'approved') {
            throw ValidationException::withMessages(['lesson_plan_id' => 'Only approved lesson plans can be recorded as taught.']);
        }if (Carbon::parse($d['date_taught'])->isFuture()) {
            throw ValidationException::withMessages(['date_taught' => 'The taught date cannot be in the future.']);
        }if (RecordOfWork::where('lesson_plan_id', $plan->id)->exists()) {
            throw ValidationException::withMessages(['lesson_plan_id' => 'A record of work already exists for this lesson plan.']);
        }

        return RecordOfWork::create([...$d, 'id' => (string) Str::uuid(), 'school_id' => $school, 'created_by' => $user ?? $d['created_by'] ?? null, 'status' => $d['status'] ?? 'completed', 'is_deleted' => false, 'created_at' => now()]);
    }
}
