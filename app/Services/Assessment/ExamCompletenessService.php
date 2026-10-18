<?php

namespace App\Services\Assessment;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamCompletenessService
{
    public function __construct(private readonly ExamResultLifecycleService $lifecycle) {}

    public function report(string $schoolId, string $examId): array
    {
        return DB::transaction(function () use ($schoolId, $examId) {
            // Serialize this report with roster replacement and result writes.
            $this->lifecycle->lockExam($schoolId, $examId);

            $roster = DB::table('exam_rosters')->where('school_id', $schoolId)
                ->where('exam_id', $examId)->first();

            if (! $roster) {
                return [
                    'exam_id' => $examId,
                    'roster_configured' => false,
                    'complete' => false,
                    'learners' => [],
                    'issues' => ['roster_not_configured'],
                ];
            }

            $participants = DB::table('exam_roster_learners')
                ->where('roster_id', $roster->id)->orderBy('learner_id')->get();

            $requirements = DB::table('exam_roster_papers')
                ->whereIn('roster_learner_id', $participants->pluck('id'))
                ->orderBy('paper_number')->orderBy('paper_id')
                ->get()->groupBy('roster_learner_id');

            $raw = DB::table('exam_results')->where('exam_id', $examId)
                ->where('is_deleted', false)->orderBy('id')->get();

            $processed = DB::table('learning_area_results')
                ->where('school_id', $schoolId)->where('exam_id', $examId)
                ->where('is_deleted', false)->get();

            $rawByPaper = $raw->groupBy(fn ($row) => $row->learner_id.'|'.$row->learning_area_id.'|'.$row->paper_id
            );
            $processedByArea = $processed->groupBy(fn ($row) => $row->learner_id.'|'.$row->learning_area_id
            );

            $currentPapers = DB::table('exam_papers')
                ->whereIn('exam_learning_area_id', $requirements->flatten()->pluck('exam_learning_area_id')->unique())
                ->where('is_deleted', false)->get()->keyBy('id');
            $currentPapersByArea = $currentPapers->groupBy('exam_learning_area_id');

            $currentAreas = DB::table('exam_learning_areas')
                ->where('exam_id', $examId)->where('is_deleted', false)
                ->get()->keyBy('id');

            $expectedRaw = [];
            $expectedAreas = [];
            $learners = [];

            foreach ($participants as $participant) {
                $areas = [];
                $selected = $requirements->get($participant->id, collect());

                foreach ($selected->groupBy('learning_area_id') as $areaId => $papers) {
                    $issues = [];
                    $missing = [];
                    $invalid = [];
                    $absent = [];
                    $total = 0.0;
                    $maximum = (float) $papers->sum('maximum_marks');
                    $snapshot = $papers->first();
                    $currentArea = $currentAreas->get($snapshot->exam_learning_area_id);
                    $currentPaperIds = $currentPapersByArea
                        ->get($snapshot->exam_learning_area_id, collect())
                        ->pluck('id')->sort()->values()->all();
                    $expectedPaperIds = $papers->pluck('paper_id')->sort()->values()->all();

                    if ($currentPaperIds !== $expectedPaperIds) {
                        $issues[] = 'configuration_changed';
                    }

                    $expectedAreas[$participant->learner_id.'|'.$areaId] = true;

                    if ($papers->count() !== (int) $snapshot->expected_paper_count
                        || $maximum <= 0
                        || abs($maximum - (float) $snapshot->learning_area_total_marks) > 0.001
                        || ! $currentArea
                        || (int) $currentArea->number_of_papers !== (int) $snapshot->expected_paper_count
                        || abs((float) $currentArea->total_marks - $maximum) > 0.001) {
                        $issues[] = 'configuration_changed';
                    }

                    foreach ($papers as $paper) {
                        $expectedRaw[$participant->learner_id.'|'.$areaId.'|'.$paper->paper_id] = true;
                        $current = $currentPapers->get($paper->paper_id);

                        if (! $current
                            || $current->exam_learning_area_id !== $paper->exam_learning_area_id
                            || (int) $current->paper_number !== (int) $paper->paper_number
                            || abs((float) $current->max_marks - (float) $paper->maximum_marks) > 0.001
                            || ($currentArea && $currentArea->learning_area_id !== $areaId)) {
                            $issues[] = 'configuration_changed';
                        }

                        $matches = $rawByPaper->get(
                            $participant->learner_id.'|'.$areaId.'|'.$paper->paper_id,
                            collect()
                        );

                        if ($matches->isEmpty()) {
                            $missing[] = $paper->paper_id;
                        } elseif ($matches->count() !== 1) {
                            $invalid[] = $paper->paper_id;
                        } else {
                            $rawResult = $matches->first();

                            try {
                                $value = app(PaperResultValueService::class)->normalize([
                                    'is_absent' => $rawResult->is_absent,
                                    'marks' => $rawResult->marks,
                                ], (float) $paper->maximum_marks);

                                if ($value['is_absent']) {
                                    $absent[] = $paper->paper_id;
                                } else {
                                    $total += $value['marks'];
                                }
                            } catch (ValidationException $exception) {
                                $invalid[] = $paper->paper_id;
                            }
                        }
                    }

                    if ($missing !== []) {
                        $issues[] = 'missing_papers';
                    }
                    if ($invalid !== []) {
                        $issues[] = 'invalid_paper_results';
                    }

                    $areaResults = $processedByArea->get(
                        $participant->learner_id.'|'.$areaId,
                        collect()
                    );
                    $result = $areaResults->first();

                    if (! $result) {
                        $issues[] = 'unprocessed';
                    } elseif ($areaResults->count() !== 1) {
                        $issues[] = 'invalid_processed_results';
                    } elseif ($result->processing_status !== 'processed') {
                        $issues[] = 'stale_or_unprocessed';
                    } elseif ($missing === [] && $invalid === [] && $maximum > 0) {
                        $expectsAbsence = $absent !== [];
                        $maximumMatches = is_numeric($result->maximum_marks)
                            && is_finite((float) $result->maximum_marks)
                            && abs((float) $result->maximum_marks - $maximum) <= 0.001;

                        if ($expectsAbsence) {
                            $valuesMatch = $result->marks_obtained === null
                                && $result->percentage === null
                                && $result->grading_system_id === null
                                && $result->grading_scale_id === null;
                        } else {
                            $valuesMatch = is_numeric($result->marks_obtained)
                                && is_finite((float) $result->marks_obtained)
                                && is_numeric($result->percentage)
                                && is_finite((float) $result->percentage)
                                && abs((float) $result->marks_obtained - round($total, 2)) <= 0.001
                                && abs((float) $result->percentage - round($total / $maximum * 100, 2)) <= 0.001
                                && $result->grading_system_id !== null
                                && $result->grading_scale_id !== null;
                        }

                        if ((bool) $result->is_absent !== $expectsAbsence
                            || ! $maximumMatches || ! $valuesMatch) {
                            $issues[] = 'processed_values_mismatch';
                        }
                    }

                    $areas[] = [
                        'learning_area_id' => $areaId,
                        'expected_paper_count' => $papers->count(),
                        'missing_paper_ids' => $missing,
                        'invalid_paper_ids' => $invalid,
                        'absent_paper_ids' => $absent,
                        'absent_paper_count' => count($absent),
                        'complete' => $issues === [],
                        'issues' => array_values(array_unique($issues)),
                    ];
                }

                $learners[] = [
                    'learner_id' => $participant->learner_id,
                    'grade_id' => $participant->grade_id,
                    'stream_id' => $participant->stream_id,
                    'complete' => $areas !== [] && collect($areas)->every(fn ($area) => $area['complete']),
                    'learning_areas' => $areas,
                    'issues' => $areas === [] ? ['no_required_papers'] : [],
                ];
            }

            $unexpectedRaw = $raw->reject(fn ($row) => isset($expectedRaw[$row->learner_id.'|'.$row->learning_area_id.'|'.$row->paper_id])
            )->count();
            $unexpectedProcessed = $processed->reject(fn ($row) => isset($expectedAreas[$row->learner_id.'|'.$row->learning_area_id])
            )->count();

            return [
                'exam_id' => $examId,
                'roster_configured' => true,
                'roster_version' => (int) $roster->version,
                'learner_count' => count($learners),
                'complete_learner_count' => collect($learners)->where('complete', true)->count(),
                'unexpected_paper_result_count' => $unexpectedRaw,
                'unexpected_learning_area_result_count' => $unexpectedProcessed,
                'complete' => $learners !== []
                    && collect($learners)->every(fn ($learner) => $learner['complete'])
                    && $unexpectedRaw === 0 && $unexpectedProcessed === 0,
                'learners' => $learners,
                'issues' => $learners === [] ? ['empty_roster'] : [],
            ];
        });
    }
}
