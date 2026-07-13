<?php

namespace App\Services\Homework;

use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Support\Facades\DB;

class HomeworkAnalyticsService
{
    public function __construct(private LeadershipPortalAccessService $access) {}

    public function summary(User $u): array
    {
        $scope = $this->access->scope($u);
        $a = DB::table('homework_assignments')->where('school_id', $u->school_id)->where('is_deleted', false)->when(! $scope['whole_school'], fn ($q) => $q->whereIn('learning_area_id', $scope['learning_area_ids']));
        $ids = (clone $a)->select('id');
        $learners = DB::table('homework_assignment_learners')->where('school_id', $u->school_id)->whereIn('assignment_id', $ids);
        $marks = DB::table('homework_submission_marks')->where('school_id', $u->school_id)->whereIn('assignment_id', $ids);

        return ['total_assignments' => (clone $a)->count(), 'published' => (clone $a)->where('status', 'published')->count(), 'by_teacher' => (clone $a)->selectRaw('teacher_id,COUNT(*) total')->groupBy('teacher_id')->get(), 'by_learning_area' => (clone $a)->selectRaw('learning_area_id,COUNT(*) total')->groupBy('learning_area_id')->get(), 'by_grade' => (clone $a)->selectRaw('grade_id,stream_id,COUNT(*) total')->groupBy('grade_id', 'stream_id')->get(), 'learner_status' => (clone $learners)->selectRaw('submission_status,COUNT(*) total')->groupBy('submission_status')->get(), 'mark_status' => (clone $marks)->selectRaw('status,COUNT(*) total')->groupBy('status')->get(), 'average_score' => (clone $marks)->whereNotNull('percentage')->avg('percentage')];
    }
}
