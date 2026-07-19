<?php

namespace App\Services\ParentPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentPortalAnalyticsService
{
    public function __construct(private ParentPortalAccessService $access) {}

    public function analytics(User $user): array
    {
        $learnerIds = $this->access->links($user)->pluck('learner_id');
        $attempts = DB::table('parent_payment_attempts')->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->where('initiated_at', '>=', now()->subYear());
        $appointments = DB::table('parent_appointments')->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $consents = DB::table('parent_consent_responses')->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->selectRaw('response, COUNT(*) AS total')->groupBy('response')->pluck('total', 'response');

        return [
            'payments' => ['total_attempts' => (clone $attempts)->count(), 'successful' => (clone $attempts)->where('status', 'completed')->count(), 'failed' => (clone $attempts)->where('status', 'failed')->count(), 'pending' => (clone $attempts)->whereIn('status', ['pending', 'awaiting_customer', 'processing'])->count(), 'completed_amount_minor' => (int) (clone $attempts)->where('status', 'completed')->sum('amount_minor')],
            'receipts_available' => (clone $attempts)->where('status', 'completed')->whereNotNull('payment_id')->count(),
            'outstanding_fee_total' => DB::table('learner_fee_accounts')->where('school_id', $user->school_id)->whereIn('learner_id', $learnerIds)->sum('current_balance'),
            'consents' => $consents, 'appointments' => $appointments,
            'unread_messages' => DB::table('parent_conversation_messages as message')->join('parent_conversations as conversation', 'conversation.id', '=', 'message.conversation_id')->where('conversation.school_id', $user->school_id)->where('conversation.parent_user_id', $user->id)->where('message.sender_type', '!=', 'parent')->count(),
            'devices' => ['active' => DB::table('parent_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->count(), 'push_enabled' => DB::table('parent_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->where('push_enabled', true)->count()],
            'sync' => ['open_conflicts' => DB::table('parent_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->count(), 'last_sync_at' => DB::table('parent_sync_operations')->where('user_id', $user->id)->max('created_at')],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
