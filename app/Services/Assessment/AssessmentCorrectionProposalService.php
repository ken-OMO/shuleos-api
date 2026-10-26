<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AssessmentCorrectionProposalService
{
    public function __construct(
        private readonly ExamResultLifecycleService $lifecycle,
        private readonly PaperResultValueService $values
    ) {}

    /**
     * Validate and capture an explicit before/after proposal.
     * This operation never writes marks or reopens published outputs.
     */
    public function normalize(string $schoolId, string $examId, array $changes): array
    {
        Validator::make(['changes' => $changes], [
            'changes' => 'required|array|min:1|max:100',
            'changes.*' => 'required|array:exam_result_id,marks,is_absent',
            'changes.*.exam_result_id' => 'required|uuid|distinct',
            'changes.*.marks' => 'present|nullable|numeric',
            'changes.*.is_absent' => 'required|boolean',
        ])->validate();

        return DB::transaction(function () use ($schoolId, $examId, $changes): array {
            $this->lifecycle->lockExamForWrite($schoolId, $examId);

            $changes = collect($changes)->sortBy('exam_result_id')->values();
            $results = DB::table('exam_results')
                ->where('exam_id', $examId)
                ->where('is_deleted', false)
                ->whereIn('id', $changes->pluck('exam_result_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $proposal = [];

            foreach ($changes as $index => $change) {
                $result = $results->get($change['exam_result_id']);

                if (! $result) {
                    throw ValidationException::withMessages([
                        "changes.$index.exam_result_id" => 'The result is unavailable in this school and exam.',
                    ]);
                }

                $paper = DB::table('exam_papers as paper')
                    ->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')
                    ->where('paper.id', $result->paper_id)
                    ->where('paper.is_deleted', false)
                    ->where('area.is_deleted', false)
                    ->where('area.exam_id', $examId)
                    ->where('area.learning_area_id', $result->learning_area_id)
                    ->select('paper.max_marks')->first();

                if (! $paper) {
                    throw ValidationException::withMessages([
                        "changes.$index.exam_result_id" => 'The result has no available matching exam paper.',
                    ]);
                }

                $after = $this->values->normalize([
                    'marks' => $change['marks'],
                    'is_absent' => (bool) $change['is_absent'],
                ], (float) $paper->max_marks);

                $before = [
                    'marks' => $result->marks,
                    'is_absent' => (bool) $result->is_absent,
                ];

                $sameMarks = $before['marks'] === null
                    ? $after['marks'] === null
                    : ($after['marks'] !== null
                        && (float) $before['marks'] === (float) $after['marks']);

                if ($sameMarks && $before['is_absent'] === (bool) $after['is_absent']) {
                    throw ValidationException::withMessages([
                        "changes.$index" => 'The proposed result must differ from the stored result.',
                    ]);
                }

                $proposal[] = [
                    'exam_result_id' => $result->id,
                    'learner_id' => $result->learner_id,
                    'learning_area_id' => $result->learning_area_id,
                    'paper_id' => $result->paper_id,
                    'before' => $before,
                    'after' => $after,
                ];
            }

            return $proposal;
        });
    }
}
