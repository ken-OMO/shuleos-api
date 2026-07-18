<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdvancedCommunicationAnalyticsService
{
    public function summary(User $user): array
    {
        $deliveries = DB::table('communication_deliveries as delivery')->join('communications as communication', 'communication.id', '=', 'delivery.communication_id')->where('delivery.school_id', $user->school_id);
        $totalEmail = (clone $deliveries)->where('delivery.channel', 'email')->count();
        $totalSms = (clone $deliveries)->where('delivery.channel', 'sms')->count();
        $count = fn (string $channel, array $statuses) => (clone $deliveries)->where('delivery.channel', $channel)->whereIn('delivery.status', $statuses)->count();
        $percentage = fn (int $value, int $total) => $total ? round($value / $total * 100, 2) : null;
        $emailAccepted = $count('email', ['accepted', 'sent', 'delivered', 'opened', 'clicked']);
        $smsAccepted = $count('sms', ['accepted', 'sent', 'delivered']);

        return [
            'email' => ['accepted_rate' => $percentage($emailAccepted, $totalEmail), 'delivered_rate' => $percentage($count('email', ['delivered', 'opened', 'clicked']), $emailAccepted), 'bounce_rate' => $percentage($count('email', ['bounced']), $totalEmail), 'complaint_rate' => $percentage($count('email', ['complained']), $totalEmail), 'open_rate_provider_dependent' => $percentage($count('email', ['opened', 'clicked']), $count('email', ['delivered', 'opened', 'clicked'])), 'click_rate_provider_dependent' => $percentage($count('email', ['clicked']), $count('email', ['delivered', 'opened', 'clicked']))],
            'sms' => ['acceptance_rate' => $percentage($smsAccepted, $totalSms), 'delivery_rate' => $percentage($count('sms', ['delivered']), $smsAccepted), 'credits_consumed' => (int) (clone $deliveries)->where('delivery.channel', 'sms')->sum('delivery.credits_used'), 'estimated_cost_minor' => (int) (clone $deliveries)->where('delivery.channel', 'sms')->sum('delivery.cost_minor')],
            'wallet_balance' => DB::table('school_sms_wallets')->where('school_id', $user->school_id)->value('balance_credits'),
            'contact_health' => DB::table('communication_contact_health')->where('school_id', $user->school_id)->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status'),
            'recurring' => ['active' => DB::table('recurring_communication_schedules')->where('school_id', $user->school_id)->where('status', 'active')->count(), 'sent_runs' => DB::table('recurring_communication_runs')->where('school_id', $user->school_id)->where('status', 'sent')->count()],
            'digests_generated' => DB::table('communication_digest_runs')->where('school_id', $user->school_id)->count(),
            'emergency_communications' => DB::table('communications')->where('school_id', $user->school_id)->where('communication_type', 'emergency')->count(),
            'failure_reasons' => (clone $deliveries)->whereNotNull('delivery.failure_code')->selectRaw('delivery.failure_code, COUNT(*) total')->groupBy('delivery.failure_code')->pluck('total', 'failure_code'),
        ];
    }
}
