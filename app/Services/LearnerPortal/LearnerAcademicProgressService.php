<?php

namespace App\Services\LearnerPortal;

use App\Models\User;
use App\Services\Attendance\AttendanceReadService;
use Illuminate\Support\Facades\DB;

class LearnerAcademicProgressService
{
    public function __construct(private LearnerPortalAccessService $access, private AttendanceReadService $attendance) {}

    public function summary(User $user, ?string $learningArea = null, int $periods = 6): array
    {
        $learner = $this->access->learner($user);
        $periods = max(1, min($periods, 12));
        $query = DB::table('learning_area_results as results')->join('exams', 'exams.id', '=', 'results.exam_id')->leftJoin('grading_scales', 'grading_scales.id', '=', 'results.grading_scale_id')->where('results.school_id', $user->school_id)->where('results.learner_id', $learner->id)->where('results.processing_status', 'processed')->where('results.is_deleted', false)->where('exams.status', 'published');
        if ($learningArea) {
            abort_unless(DB::table('learning_area_results')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('learning_area_id', $learningArea)->exists(), 404);
            $query->where('results.learning_area_id', $learningArea);
        }
        $rows = $query->orderByDesc('results.processed_at')->limit($periods * 20)->get(['results.exam_id', 'results.learning_area_id', 'results.percentage', 'grading_scales.grade_code', 'grading_scales.points', 'results.processed_at']);
        $homework = DB::table('homework_assignment_learners')->where('school_id', $user->school_id)->where('learner_id', $learner->id);
        $assigned = (clone $homework)->count();
        $completed = (clone $homework)->whereIn('submission_status', ['submitted', 'late', 'resubmitted', 'reviewed', 'released'])->count();

        $areas = $rows->groupBy('learning_area_id')->map(function ($items, $id) {
            $chronological = $items->sortBy('processed_at')->values();
            $change = $chronological->count() > 1 ? round((float) $chronological->last()->percentage - (float) $chronological->first()->percentage, 2) : null;

            return ['learning_area_id' => $id, 'average_percentage' => round((float) $items->avg('percentage'), 2), 'periods' => $items->count(), 'latest_grade' => $items->first()->grade_code, 'change_percentage_points' => $change, 'direction' => $change === null ? 'insufficient_data' : ($change > 0 ? 'improving' : ($change < 0 ? 'declining' : 'stable'))];
        })->values();

        return ['published_periods' => $rows->pluck('exam_id')->unique()->count(), 'average_percentage' => $rows->isEmpty() ? null : round((float) $rows->avg('percentage'), 2), 'grade_distribution' => $rows->whereNotNull('grade_code')->countBy('grade_code'), 'learning_areas' => $areas, 'homework' => ['assigned' => $assigned, 'completed' => $completed, 'completion_rate' => $assigned ? round($completed / $assigned * 100, 2) : null], 'attendance' => $this->attendance->summary($this->attendance->learner($user)->whereDate('attendance_date', '>=', today()->subYear())), 'report_card_history' => DB::table('report_cards')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('status', 'published')->where('is_deleted', false)->latest('published_at')->limit($periods)->get(['id', 'exam_id', 'overall_grade', 'average_percentage', 'published_at']), 'calculation' => 'Averages use only published processed results; change compares oldest and newest bounded period percentages.', 'ranking_included' => false, 'ai_prediction' => false];
    }

    public function trends(User $user, int $periods = 6): array
    {
        $summary = $this->summary($user, null, $periods);

        return ['period_limit' => max(1, min($periods, 12)), 'learning_areas' => $summary['learning_areas'], 'calculation' => "Arithmetic average of the learner's published processed percentages."];
    }
}
