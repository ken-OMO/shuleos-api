<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamLearningAreaAnalysisService
{
    public function __construct(
        private readonly LeadershipPortalAccessService $access
    ) {}

    public function analyse(User $user, string $examId, array $filters = []): array
    {
        $this->access->require($user, 'view_academic_insights');
        $scope = $this->access->scope($user);
        $schoolId = $scope['school_id'];

        abort_unless(Str::isUuid($examId), 404);
        $exam = Exam::current()
            ->where('school_id', $schoolId)
            ->findOrFail($examId);

        $gradeId = $filters['grade_id'] ?? null;
        $streamId = $filters['stream_id'] ?? null;
        $areaId = $filters['learning_area_id'] ?? null;
        $topLimit = (int) ($filters['top_limit'] ?? 10);

        if ($topLimit < 1 || $topLimit > 50) {
            throw ValidationException::withMessages([
                'top_limit' => 'The top limit must be between 1 and 50.',
            ]);
        }

        if ($gradeId) {
            abort_unless(
                DB::table('grades')->where('school_id', $schoolId)
                    ->where('id', $gradeId)->exists(),
                404
            );
        }

        if ($streamId) {
            $stream = DB::table('streams')->where('school_id', $schoolId)
                ->where('id', $streamId)->first();
            abort_unless($stream, 404);
            abort_unless(
                DB::table('grades')->where('school_id', $schoolId)
                    ->where('id', $stream->grade_id)->exists(),
                404
            );

            if ($gradeId && $stream->grade_id !== $gradeId) {
                throw ValidationException::withMessages([
                    'stream_id' => 'The stream does not belong to the selected grade.',
                ]);
            }
        }

        if ($areaId) {
            $this->access->assertLearningArea($user, $areaId);
        }

        $query = DB::table('learning_area_results as results')
            ->join('learners as learners', function ($join) {
                $join->on('learners.id', '=', 'results.learner_id')
                    ->on('learners.school_id', '=', 'results.school_id');
            })
            ->where('results.school_id', $schoolId)
            ->where('results.exam_id', $exam->id)
            ->where('results.is_deleted', false)
            ->where('results.processing_status', 'processed');

        if ($scope['role_key'] === 'hod') {
            $query->whereIn('results.learning_area_id', $scope['learning_area_ids']);
        }

        $query->when($gradeId, fn ($q) => $q->where('learners.grade_id', $gradeId))
            ->when($streamId, fn ($q) => $q->where('learners.stream_id', $streamId))
            ->when($areaId, fn ($q) => $q->where('results.learning_area_id', $areaId));

        $rows = $query->orderBy('results.learning_area_id')
            ->orderBy('results.learner_id')
            ->get([
                'results.learner_id', 'results.learning_area_id',
                'results.marks_obtained', 'results.maximum_marks',
                'results.percentage', 'results.grading_system_id',
                'results.grading_scale_id', 'learners.grade_id',
                'learners.stream_id',
            ]);

        $systems = DB::table('grading_systems')
            ->where('school_id', $schoolId)
            ->whereIn('id', $rows->pluck('grading_system_id')->unique())
            ->get(['id', 'grading_name'])->keyBy('id');

        $scales = DB::table('grading_scales')
            ->whereIn('grading_system_id', $systems->keys())
            ->orderBy('sort_order')->orderBy('id')
            ->get([
                'id', 'grading_system_id', 'grade_code',
                'grade_description', 'min_score', 'max_score', 'points',
            ])
            ->groupBy('grading_system_id');

        foreach ($rows as $row) {
            $systemScales = $scales->get($row->grading_system_id, collect());
            $storedScale = $systemScales->firstWhere('id', $row->grading_scale_id);

            if (! $systems->has($row->grading_system_id) || ! $storedScale
                || ! is_numeric($row->percentage)
                || (float) $row->percentage < 0 || (float) $row->percentage > 100
                || ! is_numeric($row->maximum_marks)
                || (float) $row->maximum_marks <= 0
                || ! is_numeric($row->marks_obtained)
                || (float) $row->marks_obtained < 0
                || (float) $row->marks_obtained > (float) $row->maximum_marks) {
                throw ValidationException::withMessages([
                    'analysis' => 'A processed result has invalid scores or grading references.',
                ]);
            }
        }

        $includeLearners = ! $scope['executive_summary_only'];

        $areas = $rows->groupBy('learning_area_id')
            ->map(function (Collection $areaRows, string $id) use ($systems, $scales, $includeLearners, $topLimit) {
                return [
                    'learning_area_id' => $id,
                    'assessed_learners' => $areaRows->pluck('learner_id')->unique()->count(),
                    'grading_summaries' => $this->summaries(
                        $areaRows, $systems, $scales, $includeLearners, $topLimit
                    ),
                    'streams' => $areaRows->groupBy(
                        fn ($row) => $row->grade_id.'|'.($row->stream_id ?? '')
                    )->map(fn (Collection $streamRows) => [
                        'grade_id' => $streamRows->first()->grade_id,
                        'stream_id' => $streamRows->first()->stream_id,
                        'grading_summaries' => $this->summaries(
                            $streamRows, $systems, $scales, $includeLearners, $topLimit
                        ),
                    ])->values()->all(),
                ];
            })->values()->all();

        return [
            'exam_id' => $exam->id,
            'filters' => [
                'grade_id' => $gradeId,
                'stream_id' => $streamId,
                'learning_area_id' => $areaId,
                'top_limit' => $topLimit,
            ],
            'membership_basis' => 'current',
            'grading_basis' => 'stored_system_current_scale_boundaries',
            'ranking_method' => 'competition',
            'assessed_learners' => $rows->pluck('learner_id')->unique()->count(),
            'learning_areas' => $areas,
        ];
    }

    private function summaries(
        Collection $rows,
        Collection $systems,
        Collection $scales,
        bool $includeLearners,
        int $topLimit
    ): array {
        return $rows->groupBy('grading_system_id')->sortKeys()
            ->map(function (Collection $items, string $systemId) use ($systems, $scales, $includeLearners, $topLimit) {
                $mean = round((float) $items->avg('percentage'), 2);
                $systemScales = $scales->get($systemId, collect());
                $matches = $systemScales->filter(fn ($scale) => $scale->min_score !== null && $scale->max_score !== null
                    && (float) $scale->min_score <= $mean
                    && (float) $scale->max_score >= $mean
                );

                if ($matches->count() !== 1) {
                    throw ValidationException::withMessages([
                        'grading_scale' => 'The mean percentage must match exactly one grading scale.',
                    ]);
                }

                $meanScale = $matches->first();
                $counts = $items->countBy('grading_scale_id');
                $summary = [
                    'grading_system_id' => $systemId,
                    'grading_name' => $systems->get($systemId)->grading_name,
                    'assessed_learners' => $items->count(),
                    'mean_percentage' => $mean,
                    'mean_grade' => $meanScale->grade_code,
                    'mean_grade_description' => $meanScale->grade_description,
                    'mean_grade_points' => $meanScale->points,
                    'lowest_percentage' => (float) $items->min('percentage'),
                    'highest_percentage' => (float) $items->max('percentage'),
                    'mark_summaries' => $items->groupBy(
                        fn ($row) => number_format((float) $row->maximum_marks, 2, '.', '')
                    )->sortKeys()->map(fn (Collection $markRows, string $maximum) => [
                        'maximum_marks' => (float) $maximum,
                        'assessed_learners' => $markRows->count(),
                        'mean_score' => round((float) $markRows->avg('marks_obtained'), 2),
                    ])->values()->all(),
                    'grade_distribution' => $systemScales->map(fn ($scale) => [
                        'grading_scale_id' => $scale->id,
                        'grade_code' => $scale->grade_code,
                        'points' => $scale->points,
                        'learner_count' => $counts->get($scale->id, 0),
                        'percentage' => round(
                            $counts->get($scale->id, 0) / $items->count() * 100, 2
                        ),
                    ])->values()->all(),
                ];

                if ($includeLearners) {
                    $rank = 0;
                    $previous = null;
                    $summary['top_learners'] = $items->sort(function ($a, $b) {
                        return ((float) $b->percentage <=> (float) $a->percentage)
                            ?: strcmp($a->learner_id, $b->learner_id);
                    })->values()->map(function ($row, int $index) use (&$rank, &$previous) {
                        $percentage = (float) $row->percentage;
                        if ($previous === null || $percentage !== $previous) {
                            $rank = $index + 1;
                        }
                        $previous = $percentage;

                        return [
                            'learner_id' => $row->learner_id,
                            'rank' => $rank,
                            'marks_obtained' => (float) $row->marks_obtained,
                            'maximum_marks' => (float) $row->maximum_marks,
                            'percentage' => $percentage,
                        ];
                    })->take($topLimit)->all();
                }

                return $summary;
            })->values()->all();
    }
}
