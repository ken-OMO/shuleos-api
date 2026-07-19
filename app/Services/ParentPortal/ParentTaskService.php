<?php

namespace App\Services\ParentPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentTaskService
{
    public function __construct(private ParentPortalAccessService $access) {}

    public function tasks(User $user): array
    {
        $learnerIds = $this->access->links($user)->pluck('learner_id');
        $tasks = collect();
        DB::table('parent_payment_attempts')->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->whereIn('status', ['pending', 'awaiting_customer', 'failed', 'reconciliation_required'])->latest('initiated_at')->limit(20)->get()->each(function ($attempt) use ($tasks) {
            $tasks->push(['id' => 'payment:'.$attempt->id, 'type' => $attempt->status === 'failed' ? 'payment_failed' : 'payment_pending', 'learner_id' => $attempt->learner_id, 'title' => $attempt->status === 'failed' ? 'Payment needs attention' : 'Payment pending', 'due_at' => $attempt->expired_at]);
        });
        DB::table('parent_consent_audiences as audience')->join('parent_consents as consent', 'consent.id', '=', 'audience.consent_id')
            ->leftJoin('parent_consent_responses as response', function ($join) use ($user) {
                $join->on('response.consent_id', '=', 'consent.id')->on('response.learner_id', '=', 'audience.learner_id')->where('response.parent_user_id', $user->id);
            })
            ->where('audience.school_id', $user->school_id)->whereIn('audience.learner_id', $learnerIds)->where('consent.status', 'published')->whereNull('response.id')->where(fn ($query) => $query->whereNull('consent.expires_at')->orWhere('consent.expires_at', '>', now()))
            ->limit(20)->get(['consent.id', 'consent.title', 'consent.expires_at', 'audience.learner_id'])->each(fn ($consent) => $tasks->push(['id' => 'consent:'.$consent->id.':'.$consent->learner_id, 'type' => 'consent_required', 'learner_id' => $consent->learner_id, 'title' => $consent->title, 'due_at' => $consent->expires_at]));
        DB::table('parent_appointments')->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->whereIn('status', ['proposed', 'confirmed'])->limit(20)->get()->each(fn ($item) => $tasks->push(['id' => 'appointment:'.$item->id, 'type' => 'appointment_update', 'learner_id' => $item->learner_id, 'title' => 'Appointment '.$item->status, 'due_at' => $item->proposed_at]));

        return $tasks->unique('id')->take(50)->values()->all();
    }
}
