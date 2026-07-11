<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\ExamLearningArea;
use App\Models\LearningArea;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamLearningAreaService
{
    public function create(array $d, string $school): ExamLearningArea
    {
        $exam = Exam::current()->whereKey($d['exam_id'])->where('school_id', $school)->where('status', 'draft')->where('active', true)->first();
        $area = LearningArea::whereKey($d['learning_area_id'])->where('active', true)->first();
        if (! $exam || ! $area) {
            throw ValidationException::withMessages(['exam_learning_area' => 'The exam must be an active tenant draft and the learning area must be active.']);
        }if (ExamLearningArea::current()->where('exam_id', $exam->id)->where('learning_area_id', $area->id)->exists()) {
            throw ValidationException::withMessages(['learning_area_id' => 'This learning area is already attached to the exam.']);
        }

return ExamLearningArea::create([...$d, 'id' => (string) Str::uuid(), 'is_deleted' => false, 'created_at' => now()]);
    }
}
