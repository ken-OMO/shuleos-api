<?php

namespace App\Services\ParentPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentLearnerProgressService
{
    public function __construct(private ParentPortalAccessService $access, private ParentPortalMobileService $portal) {}

    public function summary(User $user, string $learnerId, string $section = 'summary'): array
    {
        $learner = $this->access->requireLinkedLearner($user, $learnerId);
        $attendance = $this->portal->attendanceSummary($user, $learnerId);
        $homework = $this->portal->homework($user, $learnerId, []);
        $cards = $this->portal->reportCards($user, $learnerId);
        $results = $this->portal->results($user, $learnerId);
        $completed = collect($homework)->whereIn('submission_status', ['submitted', 'graded', 'returned'])->count();
        $total = collect($homework)->count();
        $data = [
            'learner' => ['id' => $learner->id, 'name' => trim($learner->first_name.' '.$learner->last_name)],
            'academics' => ['published_results' => collect($results)->take(20)->values(), 'published_report_cards' => collect($cards)->take(10)->values()],
            'attendance' => $attendance,
            'homework' => ['total' => $total, 'completed' => $completed, 'completion_percentage' => $total ? round($completed * 100 / $total, 2) : null, 'items' => collect($homework)->take(20)->values()],
            'trends' => $this->trends($user, $learnerId),
            'generated_at' => now()->toIso8601String(),
        ];

        return $section === 'summary' ? $data : [$section => $data[$section] ?? []];
    }

    private function trends(User $user, string $learnerId): array
    {
        $rows = DB::table('learning_area_results as result')->join('exams as exam', 'exam.id', '=', 'result.exam_id')
            ->where('result.school_id', $user->school_id)->where('result.learner_id', $learnerId)->where('result.processing_status', 'processed')
            ->where('result.is_deleted', false)->where('exam.status', 'published')->orderBy('result.processed_at')->limit(100)
            ->get(['result.learning_area_id', 'result.percentage', 'result.is_absent', 'result.processed_at'])->groupBy('learning_area_id');

        return $rows->map(function ($values, $area) {
            $scored = $values->reject(fn ($row) => (bool) $row->is_absent)
                ->whereNotNull('percentage')->values();
            $first = $scored->isEmpty() ? null : (float) $scored->first()->percentage;
            $last = $scored->isEmpty() ? null : (float) $scored->last()->percentage;
            $change = $scored->count() > 1 ? round($last - $first, 2) : null;

            return [
                'learning_area_id' => $area,
                'first_percentage' => $first,
                'latest_percentage' => $last,
                'scored_periods' => $scored->count(),
                'absent_periods' => $values->filter(fn ($row) => (bool) $row->is_absent)->count(),
                'change' => $change,
                'indicator' => $change === null ? 'insufficient_data' : ($change > 0 ? 'improving' : ($change < 0 ? 'declining' : 'stable')),
            ];
        })->values()->all();
    }
}
