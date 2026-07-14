<?php

namespace App\Services\Behaviour;

use App\Models\BehaviourRecognition;
use App\Models\DisciplineAction;
use App\Models\DisciplineCase;
use App\Models\DisciplineCategory;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BehaviourService
{
    public function teacher(User $u)
    {
        return DB::table('teachers')->where('user_id', $u->id)->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->first() ?: throw new AuthorizationException('Active teacher required.');
    }

    public function authorizedLearner(User $u, string $id)
    {
        $t = $this->teacher($u);
        $l = DB::table('learners')->where('id', $id)->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->first();
        if (! $l || ! DB::table('teacher_assignments')->where('teacher_id', $t->id)->where('school_id', $u->school_id)->where('grade_id', $l->grade_id)->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $l->stream_id))->where('active', true)->where('is_deleted', false)->exists()) {
            throw new AuthorizationException('Learner is outside active teacher scope.');
        }

        return $l;
    }

    public function report(User $u, array $d): DisciplineCase
    {
        $l = $this->authorizedLearner($u, $d['learner_id']);
        $c = DisciplineCategory::current()->whereKey($d['category_id'])->where('school_id', $u->school_id)->firstOrFail();

        return DB::transaction(function () use ($u, $d, $l, $c) {
            $case = DisciplineCase::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'learner_id' => $l->id, 'category_id' => $c->id, 'reported_by' => $u->id, 'incident_date' => $d['incident_date'], 'incident_time' => $d['incident_time'] ?? null, 'location' => $d['location'] ?? null, 'description' => $d['description'], 'status' => 'reported', 'severity' => $d['severity'] ?? $c->default_severity ?? $c->severity_level, 'priority' => $d['priority'] ?? 'medium', 'parent_notification_required' => $c->requires_parent_notification, 'confidential' => $d['confidential'] ?? false, 'safeguarding' => $d['safeguarding'] ?? false, 'created_at' => now()]);
            $this->audit($u, 'case_created', $l->id, $case->id, new: ['status' => 'reported']);

            return $case;
        });
    }

    public function teacherCases(User $u)
    {
        $t = $this->teacher($u);
        $assignments = DB::table('teacher_assignments')->where('teacher_id', $t->id)->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->get();

        return DisciplineCase::current()->where('school_id', $u->school_id)->where('confidential', false)->whereHas('learner', function ($q) use ($assignments) {
            $q->where(function ($x) use ($assignments) {
                foreach ($assignments as $a) {
                    $x->orWhere(fn ($z) => $z->where('grade_id', $a->grade_id)->when($a->stream_id, fn ($s) => $s->where('stream_id', $a->stream_id)));
                }
            });
        });
    }

    public function action(User $u, string $caseId, array $d, bool $leadership = false): DisciplineAction
    {
        $case = $leadership ? $this->leadershipCases($u)->whereKey($caseId)->firstOrFail() : $this->teacherCases($u)->whereKey($caseId)->firstOrFail();
        $restricted = ['suspension_recommendation', 'suspension'];
        if (! $leadership && in_array($d['action_type'], $restricted, true)) {
            throw new AuthorizationException('Restricted action requires leadership permission.');
        }$a = DisciplineAction::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'case_id' => $case->id, 'learner_id' => $case->learner_id, 'action_type' => $d['action_type'], 'action_date' => now()->toDateString(), 'remarks' => $d['remarks'] ?? null, 'assigned_by' => $u->id, 'assigned_to' => $d['assigned_to'] ?? null, 'due_at' => $d['due_at'] ?? null, 'status' => 'planned', 'follow_up_required' => $d['follow_up_required'] ?? false, 'follow_up_at' => $d['follow_up_at'] ?? null, 'visible_to_learner' => $d['visible_to_learner'] ?? false, 'visible_to_parent' => $d['visible_to_parent'] ?? false, 'is_deleted' => false, 'created_at' => now()]);
        $this->audit($u, 'action_assigned', $case->learner_id, $case->id, $a->id, new: ['action_type' => $a->action_type]);

        return $a;
    }

    public function recognize(User $u, array $d): BehaviourRecognition
    {
        $l = $this->authorizedLearner($u, $d['learner_id']);
        if (($d['points'] ?? 0) < 0) {
            throw ValidationException::withMessages(['points' => 'Recognition points cannot be negative.']);
        }$r = BehaviourRecognition::create($d + ['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'learner_id' => $l->id, 'awarded_by' => $u->id, 'awarded_at' => now(), 'status' => 'draft']);
        $this->audit($u, 'recognition_awarded', $l->id, recognition: $r->id);

        return $r;
    }

    public function leadershipCases(User $u)
    {
        $scope = app(LeadershipPortalAccessService::class)->scope($u);
        if (! $scope['whole_school']) {
            throw new AuthorizationException('General behaviour cases require whole-school leadership scope.');
        }

        return DisciplineCase::current()->where('school_id', $u->school_id);
    }

    public function transition(User $u, string $id, string $to, ?string $reason = null): DisciplineCase
    {
        return DB::transaction(function () use ($u, $id, $to, $reason) {
            $c = $this->leadershipCases($u)->whereKey($id)->lockForUpdate()->firstOrFail();
            $allowed = ['reported' => ['under_review', 'cancelled'], 'under_review' => ['action_required', 'referred', 'resolved'], 'action_required' => ['resolved', 'referred'], 'referred' => ['resolved'], 'resolved' => ['closed', 'reopened'], 'closed' => ['reopened'], 'reopened' => ['under_review']];
            if (! in_array($to, $allowed[$c->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid behaviour case transition.']);
            }if ($to === 'reopened' && ! $reason) {
                throw ValidationException::withMessages(['reason' => 'Reopening requires a reason.']);
            }$old = $c->status;
            $values = ['status' => $to, 'updated_at' => now()];
            if ($to === 'under_review') {
                $values += ['reviewed_by' => $u->id, 'reviewed_at' => now()];
            }if ($to === 'resolved') {
                $values += ['resolved_by' => $u->id, 'resolved_at' => now()];
            }if ($to === 'closed') {
                $values += ['closure_notes' => $reason];
            }$c->update($values);
            $this->audit($u, 'case_'.$to, $c->learner_id, $c->id, old: ['status' => $old], new: ['status' => $to], reason: $reason);

            return $c;
        });
    }

    public function audit(User $u, string $action, ?string $learner = null, ?string $case = null, ?string $actionId = null, ?string $recognition = null, ?string $referral = null, array $old = [], array $new = [], ?string $reason = null): void
    {
        DB::table('behaviour_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'learner_id' => $learner, 'discipline_case_id' => $case, 'action_id' => $actionId, 'recognition_id' => $recognition, 'referral_id' => $referral, 'actor_user_id' => $u->id, 'action' => $action, 'previous_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null, 'reason' => $reason, 'created_at' => now()]);
    }
}
