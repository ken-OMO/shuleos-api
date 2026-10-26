<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\Learner;
use App\Models\LearningAreaResult;
use App\Models\MeritList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentReissueCalculationService
{
    /**
     * Calculate candidate values without persisting results or outputs.
     * The workflow caller must authorize the actor and retain its transaction.
     */
    public function calculate(
        string $schoolId,
        string $examId,
        array $changes,
        string $actorId
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reissue calculations require a transaction.');
        }

        app(ExamResultLifecycleService::class)->lockExamForWrite($schoolId, $examId);
        app(ExamCompletenessService::class)->assertComplete($schoolId, $examId);

        $proposal = app(AssessmentCorrectionProposalService::class)
            ->normalize($schoolId, $examId, $changes);

        $results = LearningAreaResult::current()
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->where('processing_status', 'processed')
            ->orderBy('id')->lockForUpdate()->get();

        if ($results->isEmpty() || $results->contains(
            fn ($result) => $result->grading_snapshot === null
        )) {
            throw ValidationException::withMessages([
                'results' => 'Complete processed results with captured grading are required.',
            ]);
        }

        $learners = Learner::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', $results->pluck('learner_id')->unique())
            ->get();
        $members = app(ExamMembershipService::class)
            ->learnersAtExam($schoolId, $examId, $learners);

        foreach ($results as $result) {
            $member = $members->get($result->learner_id);
            if (! $member) {
                throw ValidationException::withMessages([
                    'learner_id' => 'A result does not resolve to exam membership.',
                ]);
            }
            $result->setRelation('learner', $member);
        }

        // Never initialize new grading rules during historical correction.
        $levelIds = $members->map(fn ($learner) => $learner->grade?->education_level_id)
            ->filter()->unique()->values();
        $capturedLevels = DB::table('exam_grading_snapshots')
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->whereIn('education_level_id', $levelIds)
            ->pluck('education_level_id')->unique();

        if ($levelIds->count() !== $capturedLevels->count()) {
            throw ValidationException::withMessages([
                'grading_snapshot' => 'Existing captured exam grading rules are required for every affected education level.',
            ]);
        }

        $paperRows = DB::table('exam_results')
            ->where('exam_id', $examId)->where('is_deleted', false)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $changedPapers = [];
        $affected = [];

        foreach ($proposal as $change) {
            $paper = (array) $paperRows->get($change['exam_result_id']);
            $paper['marks'] = $change['after']['marks'];
            $paper['is_absent'] = $change['after']['is_absent'];
            $paperRows->put($paper['id'], (object) $paper);
            $changedPapers[] = $paper;
            $affected[$paper['learner_id'].'|'.$paper['learning_area_id']] = true;
        }

        $changedAreas = [];
        foreach ($results as $result) {
            $key = $result->learner_id.'|'.$result->learning_area_id;
            if (! isset($affected[$key])) {
                continue;
            }

            $area = DB::table('exam_learning_areas')
                ->where('exam_id', $examId)
                ->where('learning_area_id', $result->learning_area_id)
                ->where('is_deleted', false)->first();

            if (! $area) {
                throw ValidationException::withMessages([
                    'learning_area_id' => 'The corrected learning area is unavailable.',
                ]);
            }

            $papers = DB::table('exam_papers')
                ->where('exam_learning_area_id', $area->id)
                ->where('is_deleted', false)->orderBy('id')->get();

            if ($papers->count() !== (int) $area->number_of_papers) {
                throw ValidationException::withMessages([
                    'papers' => 'The configured number of exam papers is incomplete.',
                ]);
            }

            $paperIds = $papers->pluck('id');
            $inputs = $paperRows->filter(
                fn ($paper) => $paper->learner_id === $result->learner_id
                    && $paper->learning_area_id === $result->learning_area_id
                    && $paperIds->contains($paper->paper_id)
            );

            $aggregate = app(PaperResultAggregationService::class)->calculate(
                $papers->map(fn ($paper): array => (array) $paper)->all(),
                $inputs->map(fn ($paper): array => (array) $paper)->values()->all(),
                (float) $area->total_marks
            );

            $award = $aggregate['is_absent'] ? [
                'format_version' => 1,
                'rules_snapshot_id' => null,
                'grading_system_id' => null,
                'grading_scale_id' => null,
                'grade_code' => null,
                'grade_description' => null,
                'points' => null,
            ] : app(ExamGradingSnapshotService::class)->calculate(
                $schoolId, $examId, $result->learner,
                $aggregate['percentage'], $actorId
            );

            $result->forceFill($aggregate + [
                'grading_system_id' => $award['grading_system_id'],
                'grading_scale_id' => $award['grading_scale_id'],
                'grading_snapshot' => $award,
                'processing_status' => 'processed',
                'processed_by' => $actorId,
                'processed_at' => now(),
            ]);

            $changedAreas[] = $result->getAttributes();
            unset($affected[$key]);
        }

        if ($affected !== []) {
            throw ValidationException::withMessages([
                'results' => 'Every proposed correction requires an existing processed learning-area result.',
            ]);
        }

        $entries = [];
        foreach ($results->groupBy('learner_id') as $items) {
            if (! $items->contains(fn ($item) => (bool) $item->is_absent)) {
                $entries[] = [
                    'learner' => $items->first()->learner,
                    'percentage' => round((float) $items->avg('percentage'), 2),
                ];
            }
        }

        $awards = $entries === [] ? []
            : app(ExamGradingSnapshotService::class)->calculateMany(
                $schoolId, $examId, $entries, $actorId
            );
        $totals = app(MeritTotalsService::class)->calculate($results, $awards);
        $ranked = app(MeritRankingService::class)->rank($totals);

        $existingMerits = MeritList::current()
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('learner_id');

        if ($existingMerits->count() !== $ranked->count()) {
            throw ValidationException::withMessages([
                'merit_lists' => 'Complete existing merit entries are required for exam-wide recalculation.',
            ]);
        }

        $merits = [];
        foreach ($ranked as $row) {
            $existing = $existingMerits->get($row['learner']->id);
            if (! $existing) {
                throw ValidationException::withMessages([
                    'merit_lists' => 'A learner has no existing merit entry.',
                ]);
            }

            $merit = clone $existing;
            $merit->forceFill([
                'total_score' => $row['total_score'],
                'maximum_marks' => $row['maximum_marks'],
                'average_percentage' => $row['average_percentage'],
                'total_points' => $row['total_points'],
                'overall_grading_system_id' => $row['grading_system_id'],
                'overall_grading_scale_id' => $row['grading_scale_id'],
                'grading_snapshot' => $row['grading_snapshot'],
                'ranking_method' => $row['ranking_method'] ?? 'competition',
                'stream_position' => $row['stream_position'],
                'grade_position' => $row['grade_position'],
                'school_position' => $row['school_position'],
            ]);
            $merits[] = $merit->getAttributes();
        }

        return [
            'paper_results' => $changedPapers,
            'learning_area_results' => $changedAreas,
            'merit_lists' => $merits,
            'all_learning_area_results' => $results
                ->map(fn ($result): array => $result->getAttributes())->all(),
        ];
    }
}
