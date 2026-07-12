<?php

namespace App\Services\Assessment;

use App\Models\ExamLearningArea;
use App\Models\ExamResult;
use App\Models\Learner;
use App\Models\LearningAreaResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResultProcessingService
{
    public function __construct(private readonly GradeCalculationService $gradeCalculation) {}

    public function process(
        string $schoolId,
        string $examLearningAreaId,
        string $learnerId,
        string $processedBy
    ): LearningAreaResult {
        return DB::transaction(function () use ($schoolId, $examLearningAreaId, $learnerId, $processedBy) {
            $examLearningArea = ExamLearningArea::current()
                ->with(['exam', 'papers' => fn ($query) => $query->current()->orderBy('paper_number')])
                ->whereKey($examLearningAreaId)
                ->whereHas('exam', fn ($query) => $query
                    ->where('school_id', $schoolId)
                    ->where('is_deleted', false))
                ->lockForUpdate()
                ->first();

            $learner = Learner::query()
                ->with('grade.educationLevel')
                ->whereKey($learnerId)
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->first();

            if (! $examLearningArea || ! $learner) {
                throw ValidationException::withMessages([
                    'result' => 'The learner or exam learning area is unavailable outside this school.',
                ]);
            }

            $papers = $examLearningArea->papers;

            if ($papers->count() !== $examLearningArea->number_of_papers) {
                throw ValidationException::withMessages([
                    'papers' => 'The configured number of exam papers is incomplete.',
                ]);
            }

            $paperIds = $papers->pluck('id');
            $results = ExamResult::current()
                ->where('exam_id', $examLearningArea->exam_id)
                ->where('learning_area_id', $examLearningArea->learning_area_id)
                ->where('learner_id', $learner->id)
                ->whereIn('paper_id', $paperIds)
                ->lockForUpdate()
                ->get();

            if ($results->count() !== $papers->count() || $results->pluck('paper_id')->unique()->count() !== $papers->count()) {
                throw ValidationException::withMessages([
                    'results' => 'All expected paper results are required before processing.',
                ]);
            }

            $marksObtained = round((float) $results->sum('marks'), 2);
            $maximumMarks = round((float) $papers->sum('max_marks'), 2);

            if ($maximumMarks <= 0 || abs($maximumMarks - (float) $examLearningArea->total_marks) > 0.001) {
                throw ValidationException::withMessages([
                    'maximum_marks' => 'The paper maximum marks do not equal the exam learning area total marks.',
                ]);
            }

            if ($marksObtained > $maximumMarks) {
                throw ValidationException::withMessages([
                    'marks_obtained' => 'Aggregated marks exceed the available maximum marks.',
                ]);
            }

            $percentage = round(($marksObtained / $maximumMarks) * 100, 2);
            $grade = $this->gradeCalculation->calculate($learner, $schoolId, $percentage);

            $identity = [
                'school_id' => $schoolId,
                'exam_id' => $examLearningArea->exam_id,
                'learner_id' => $learner->id,
                'learning_area_id' => $examLearningArea->learning_area_id,
            ];
            $result = LearningAreaResult::query()->firstOrNew($identity);
            if (! $result->exists) {
                $result->id = (string) Str::uuid();
            }
            $result->fill([
                'marks_obtained' => $marksObtained,
                'maximum_marks' => $maximumMarks,
                'percentage' => $percentage,
                'grading_system_id' => $grade['grading_system']->id,
                'grading_scale_id' => $grade['grading_scale']->id,
                'processing_status' => 'processed',
                'processed_by' => $processedBy,
                'processed_at' => now(),
                'is_deleted' => false,
                'deleted_at' => null,
                'deleted_by' => null,
            ]);
            $result->save();

            return $result->load([
                'exam',
                'learner.grade.educationLevel',
                'learningArea',
                'gradingSystem',
                'gradingScale',
                'processedBy',
            ]);
        });
    }
}
