<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\LearningAreaResult;
use App\Models\MeritList;
use App\Models\Stream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MeritListService
{
    public function __construct(private readonly GradeCalculationService $gradeCalculation) {}

    public function generate(string $schoolId, string $examId, ?string $gradeId, ?string $streamId, string $generatedBy): Collection
    {
        return DB::transaction(function () use ($schoolId, $examId, $gradeId, $streamId, $generatedBy) {
            $this->validateContext($schoolId, $examId, $gradeId, $streamId);
            $query = LearningAreaResult::current()->where('school_id', $schoolId)->where('exam_id', $examId)
                ->where('processing_status', 'processed')->with(['learner.grade.educationLevel', 'learner.stream', 'gradingScale']);
            $query->when($gradeId, fn ($q) => $q->whereHas('learner', fn ($l) => $l->where('grade_id', $gradeId)));
            $query->when($streamId, fn ($q) => $q->whereHas('learner', fn ($l) => $l->where('stream_id', $streamId)));
            $results = $query->lockForUpdate()->get();
            if ($results->isEmpty()) {
                throw ValidationException::withMessages(['results' => 'No processed learning area results were found for this selection.']);
            }

            $rows = $results->groupBy('learner_id')->map(function (Collection $items) use ($schoolId) {
                $learner = $items->first()->learner;
                $score = round((float) $items->sum('marks_obtained'), 2);
                $maximum = round((float) $items->sum('maximum_marks'), 2);
                $average = round((float) $items->avg('percentage'), 2);
                $pointValues = $items->pluck('gradingScale.points')->filter(fn ($value) => $value !== null);
                $grade = $this->gradeCalculation->calculate($learner, $schoolId, $average);

                return [
                    'learner' => $learner, 'total_score' => $score, 'maximum_marks' => $maximum,
                    'average_percentage' => $average, 'total_points' => $pointValues->isEmpty() ? null : (int) $pointValues->sum(),
                    'grading_system_id' => $grade['grading_system']->id, 'grading_scale_id' => $grade['grading_scale']->id,
                ];
            })->values();

            $schoolPositions = $this->positions($rows);
            $gradePositions = $rows->groupBy(fn ($row) => $row['learner']->grade_id)->flatMap(fn ($group) => $this->positions($group));
            $streamPositions = $rows->groupBy(fn ($row) => $row['learner']->stream_id)->flatMap(fn ($group) => $this->positions($group));
            $rows = $rows->map(function ($row) use ($schoolPositions, $gradePositions, $streamPositions) {
                $id = $row['learner']->id;
                $row['school_position'] = $schoolPositions[$id];
                $row['grade_position'] = $gradePositions[$id];
                $row['stream_position'] = $streamPositions[$id];

                return $row;
            });

            return $rows->map(function (array $row) use ($schoolId, $examId, $generatedBy) {
                $identity = ['school_id' => $schoolId, 'exam_id' => $examId, 'learner_id' => $row['learner']->id];
                $model = MeritList::query()->firstOrNew($identity);
                if (! $model->exists) {
                    $model->id = (string) Str::uuid();
                }
                $model->fill([
                    'grade_id' => $row['learner']->grade_id, 'stream_id' => $row['learner']->stream_id,
                    'total_score' => $row['total_score'], 'maximum_marks' => $row['maximum_marks'],
                    'average_percentage' => $row['average_percentage'], 'total_points' => $row['total_points'],
                    'overall_grading_system_id' => $row['grading_system_id'], 'overall_grading_scale_id' => $row['grading_scale_id'],
                    'stream_position' => $row['stream_position'], 'grade_position' => $row['grade_position'],
                    'school_position' => $row['school_position'], 'ranking_method' => 'competition',
                    'status' => 'generated', 'generated_by' => $generatedBy, 'generated_at' => now(),
                    'published_at' => null, 'is_deleted' => false, 'deleted_at' => null, 'deleted_by' => null,
                ])->save();

                return $model->load(['exam', 'learner', 'grade', 'stream', 'overallGradingSystem', 'overallGradingScale', 'generatedBy']);
            });
        });
    }

    public function publish(string $schoolId, string $examId, ?string $gradeId, ?string $streamId): Collection
    {
        return DB::transaction(function () use ($schoolId, $examId, $gradeId, $streamId) {
            $this->validateContext($schoolId, $examId, $gradeId, $streamId);
            $query = MeritList::current()->where('school_id', $schoolId)->where('exam_id', $examId)->where('status', 'generated');
            $query->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->when($streamId, fn ($q) => $q->where('stream_id', $streamId));
            $rows = $query->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['merit_lists' => 'No generated merit-list rows were found for publishing.']);
            }
            $rows->each->update(['status' => 'published', 'published_at' => now()]);

            return $rows->load(['exam', 'learner', 'grade', 'stream', 'overallGradingSystem', 'overallGradingScale', 'generatedBy']);
        });
    }

    private function validateContext(string $schoolId, string $examId, ?string $gradeId, ?string $streamId): void
    {
        if (! Exam::current()->whereKey($examId)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['exam_id' => 'The exam does not belong to this school.']);
        }
        if ($gradeId && ! Grade::query()->whereKey($gradeId)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['grade_id' => 'The grade does not belong to this school.']);
        }
        if ($streamId && ! Stream::query()->whereKey($streamId)->where('school_id', $schoolId)->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'The stream does not belong to this school or grade.']);
        }
    }

    private function positions(Collection $rows): Collection
    {
        $sorted = $rows->sort(fn ($a, $b) => $this->compare($a, $b))->values();
        $positions = collect();
        $previous = null;
        foreach ($sorted as $index => $row) {
            $key = [$row['average_percentage'], $row['total_points'], $row['total_score']];
            $position = $previous !== null && $key === $previous['key'] ? $previous['position'] : $index + 1;
            $positions->put($row['learner']->id, $position);
            $previous = ['key' => $key, 'position' => $position];
        }

        return $positions;
    }

    private function compare(array $a, array $b): int
    {
        return $b['average_percentage'] <=> $a['average_percentage']
            ?: (($b['total_points'] ?? PHP_INT_MIN) <=> ($a['total_points'] ?? PHP_INT_MIN))
            ?: $b['total_score'] <=> $a['total_score'];
    }
}
