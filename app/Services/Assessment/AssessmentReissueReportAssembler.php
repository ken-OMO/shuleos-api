<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentReissueReportAssembler
{
    /**
     * Assemble candidate payloads; preserve stored historical presentation.
     * The caller must authorize the workflow and retain its transaction.
     */
    public function assemble(string $schoolId, string $examId, array $calculated): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reissue report assembly requires a transaction.');
        }

        app(ExamResultLifecycleService::class)->lockExamForWrite($schoolId, $examId);

        $merits = collect($calculated['merit_lists'])->keyBy('learner_id');
        $totalRankedLearners = $merits->whereNotNull('school_position')->count();
        $areasByLearner = collect($calculated['all_learning_area_results'])
            ->groupBy('learner_id');

        $cards = DB::table('report_cards')
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->where('is_deleted', false)->orderBy('id')
            ->lockForUpdate()->get();

        $reports = [];

        foreach ($cards as $stored) {
            $card = (array) $stored;
            $merit = $merits->get($card['learner_id']);
            $areas = $areasByLearner->get($card['learner_id']);

            if (! $merit || ! $areas || $areas->isEmpty()) {
                throw ValidationException::withMessages([
                    'report_cards' => 'Every existing report requires recalculated merit and learning-area results.',
                ]);
            }

            if ($merit['school_id'] !== $schoolId
                || $merit['exam_id'] !== $examId
                || $card['merit_list_id'] !== $merit['id']
                || $card['grade_id'] !== $merit['grade_id']
                || $card['stream_id'] !== $merit['stream_id']) {
                throw ValidationException::withMessages([
                    'report_cards' => 'Report and merit identities must agree before reissue.',
                ]);
            }

            $award = $this->award($merit['grading_snapshot']);
            $card = array_replace($card, [
                'has_absence' => $areas->contains(fn ($area) => (bool) $area['is_absent']),
                'overall_score' => $merit['total_score'],
                'maximum_marks' => $merit['maximum_marks'],
                'average_percentage' => $merit['average_percentage'],
                'grading_snapshot' => $merit['grading_snapshot'],
                'overall_grade' => $award['grade_code'],
                'overall_grading_system_id' => $merit['overall_grading_system_id'],
                'overall_grading_scale_id' => $merit['overall_grading_scale_id'],
                'total_points' => $merit['total_points'],
                'stream_position' => $merit['stream_position'],
                'grade_position' => $merit['grade_position'],
                'school_position' => $merit['school_position'],
                'total_learners' => $totalRankedLearners,
            ]);

            $details = DB::table('report_card_learning_areas')
                ->where('report_card_id', $card['id'])
                ->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($row): array => (array) $row);

            $current = $details->filter(fn ($row) => ! $row['is_deleted']);
            $areasById = $areas->keyBy('learning_area_id');

            if ($current->count() !== $areas->count()
                || $current->pluck('learning_area_id')->unique()->count() !== $areas->count()
                || $current->pluck('learning_area_id')->diff($areasById->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'report_cards' => 'Existing report details must match the processed learning-area population.',
                ]);
            }

            $details = $details->map(function (array $detail) use ($areasById): array {
                if ($detail['is_deleted']) {
                    return $detail;
                }

                $area = $areasById->get($detail['learning_area_id']);
                $award = $this->award($area['grading_snapshot']);

                return array_replace($detail, [
                    'learning_area_result_id' => $area['id'],
                    'is_absent' => (bool) $area['is_absent'],
                    'score' => $area['marks_obtained'],
                    'marks_obtained' => $area['marks_obtained'],
                    'maximum_marks' => $area['maximum_marks'],
                    'percentage' => $area['percentage'],
                    'grading_system_id' => $area['grading_system_id'],
                    'grading_scale_id' => $area['grading_scale_id'],
                    'grading_snapshot' => $area['grading_snapshot'],
                    'grade_code' => $award['grade_code'],
                    'points' => $award['points'],
                ]);
            });

            $reports[] = [
                'schema_version' => 1,
                'output' => $card,
                'learning_areas' => $details->values()->all(),
            ];
        }

        return $reports;
    }

    private function award(mixed $snapshot): array
    {
        $award = is_string($snapshot)
            ? json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)
            : $snapshot;

        if (! is_array($award)
            || ! array_key_exists('grade_code', $award)
            || ! array_key_exists('points', $award)) {
            throw ValidationException::withMessages([
                'grading_snapshot' => 'A captured grading award is required.',
            ]);
        }

        return $award;
    }
}
