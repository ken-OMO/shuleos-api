<?php

namespace App\Services\Finance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceNotificationService
{
    public function forLearner(string $schoolId, string $learnerId, string $type, string $key, string $title, string $message): int
    {
        $users = collect();
        $learnerUser = DB::table('learners')->where('id', $learnerId)->where('school_id', $schoolId)->where('active', true)->where('portal_enabled', true)->where('is_deleted', false)->value('user_id');
        if ($learnerUser) {
            $users->push($learnerUser);
        }
        $guardianUsers = DB::table('learner_parents as link')->join('parents as guardian', 'guardian.id', '=', 'link.parent_id')->where('link.learner_id', $learnerId)->where('link.active', true)->where('link.portal_enabled', true)->where('link.is_deleted', false)->where('guardian.school_id', $schoolId)->where('guardian.active', true)->where('guardian.is_deleted', false)->pluck('guardian.user_id');
        $users = $users->merge($guardianUsers)->filter()->unique();
        $count = 0;
        foreach ($users as $userId) {
            if (! DB::table('users')->where('id', $userId)->where('school_id', $schoolId)->where('active', true)->where('is_deleted', false)->exists()) {
                continue;
            }
            $count += DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'user_id' => $userId, 'learner_id' => $learnerId, 'notification_type' => $type, 'notification_key' => $key, 'title' => $title, 'message' => $message, 'action_url' => '/fees', 'is_read' => false, 'created_at' => now()]);
        }

        return $count;
    }

    public function reminders(?User $actor = null): int
    {
        $count = 0;
        $query = DB::table('payment_plan_installments as installment')->join('payment_plans as plan', 'plan.id', '=', 'installment.payment_plan_id')->join('finance_settings as settings', 'settings.school_id', '=', 'plan.school_id')->where('plan.status', 'active')->where('settings.finance_reminders_enabled', true)->whereIn('installment.status', ['pending', 'partially_paid', 'overdue'])->select('installment.*', 'plan.school_id', 'plan.learner_id', 'settings.reminder_due_soon_days');
        if ($actor) {
            $query->where('plan.school_id', $actor->school_id);
        }
        foreach ($query->get() as $installment) {
            $due = CarbonImmutable::parse($installment->due_date);
            if ($due->isAfter(now()->addDays($installment->reminder_due_soon_days))) {
                continue;
            }
            $state = $due->isPast() && ! $due->isToday() ? 'overdue' : ($due->isToday() ? 'due_today' : 'due_soon');
            $count += $this->forLearner($installment->school_id, $installment->learner_id, 'finance_installment_'.$state, 'finance:installment:'.$installment->id.':'.$state, 'Fee installment '.str_replace('_', ' ', $state), 'A fee-plan installment is '.str_replace('_', ' ', $state).'. View your fee account for details.');
        }
        if ($actor) {
            app(FinanceAuditService::class)->record($actor, 'finance_reminders_generated', 'notifications', $actor->school_id, [], ['count' => $count]);
        }

        return $count;
    }
}
