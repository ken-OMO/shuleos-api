<?php

namespace App\Services\Behaviour;

use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class BehaviourAnalyticsService
{
    public function __construct(private LeadershipPortalAccessService $access) {}

    public function summary(User $u): array
    {
        $scope = $this->access->scope($u);
        if (! $scope['whole_school']) {
            throw new AuthorizationException('General behaviour analytics require whole-school scope.');
        }$cases = DB::table('discipline_cases')->where('school_id', $u->school_id)->where('is_deleted', false);

        return ['total_cases' => (clone $cases)->count(), 'open_cases' => (clone $cases)->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->count(), 'resolved_cases' => (clone $cases)->whereIn('status', ['resolved', 'closed'])->count(), 'by_category' => (clone $cases)->selectRaw('category_id,COUNT(*) total')->groupBy('category_id')->get(), 'by_severity' => (clone $cases)->selectRaw('severity,COUNT(*) total')->groupBy('severity')->get(), 'overdue_actions' => DB::table('discipline_actions')->where('school_id', $u->school_id)->whereIn('status', ['planned', 'active'])->where('due_at', '<', now())->count(), 'recognitions' => DB::table('behaviour_recognitions')->where('school_id', $u->school_id)->where('status', 'published')->selectRaw('recognition_type,COUNT(*) total')->groupBy('recognition_type')->get(), 'referrals' => DB::table('behaviour_counselling_referrals')->where('school_id', $u->school_id)->selectRaw('status,COUNT(*) total')->groupBy('status')->get(), 'attendance_risk_flags' => DB::table('attendance_risk_flags')->where('school_id', $u->school_id)->selectRaw('flag_type,COUNT(*) total')->groupBy('flag_type')->get()];
    }

    public function indicators(User $u): array
    {
        $this->access->scope($u);

        return DB::table('learners')->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->get()->map(function ($l) use ($u) {
            $facts = ['open_cases' => DB::table('discipline_cases')->where('school_id', $u->school_id)->where('learner_id', $l->id)->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->count(), 'open_attendance_flags' => DB::table('attendance_risk_flags')->where('school_id', $u->school_id)->where('learner_id', $l->id)->whereIn('status', ['open', 'acknowledged'])->count(), 'positive_recognitions' => DB::table('behaviour_recognitions')->where('school_id', $u->school_id)->where('learner_id', $l->id)->where('status', 'published')->count(), 'unresolved_actions' => DB::table('discipline_actions')->where('school_id', $u->school_id)->where('learner_id', $l->id)->whereIn('status', ['planned', 'active', 'overdue'])->count()];
            $risk = $facts['open_attendance_flags'] >= 2 || $facts['open_cases'] >= 3 ? 'high' : ($facts['open_cases'] || $facts['open_attendance_flags'] ? 'moderate' : 'low');

            return ['learner_id' => $l->id, 'risk_level' => $risk, 'contributing_facts' => $facts, 'automatic_action' => false];
        })->all();
    }
}
