<?php

namespace App\Services\Assessment;

use App\Models\ExamLearningArea;
use App\Models\ExamPaper;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamPaperService
{
    public function create(array $d, string $school): ExamPaper
    {
        $area = ExamLearningArea::current()->with('exam')->whereKey($d['exam_learning_area_id'])->whereHas('exam', fn ($q) => $q->where('school_id', $school)->where('status', 'draft')->where('is_deleted', false))->first();
        if (! $area) {
            throw ValidationException::withMessages(['exam_learning_area_id' => 'The exam learning area must belong to an active tenant draft exam.']);
        }$papers = ExamPaper::current()->where('exam_learning_area_id', $area->id);
        if ((clone $papers)->where('paper_number', $d['paper_number'])->exists()) {
            throw ValidationException::withMessages(['paper_number' => 'This paper number already exists for the learning area.']);
        }if ((clone $papers)->count() >= $area->number_of_papers) {
            throw ValidationException::withMessages(['paper_number' => 'The declared number of papers has already been reached.']);
        }if ((clone $papers)->sum('max_marks') + $d['max_marks'] > $area->total_marks) {
            throw ValidationException::withMessages(['max_marks' => 'Paper marks would exceed the learning area total marks.']);
        }

return ExamPaper::create([...$d, 'id' => (string) Str::uuid(), 'paper_name' => trim($d['paper_name']), 'is_deleted' => false, 'created_at' => now()]);
    }
}
