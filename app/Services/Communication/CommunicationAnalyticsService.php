<?php

namespace App\Services\Communication;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CommunicationAnalyticsService
{
    public function __construct(private CommunicationRecipientResolverService $resolver) {}

    public function summary(User $user): array
    {
        $communications = DB::table('communications')->where('school_id', $user->school_id)->when(! $this->resolver->hasPermission($user, 'view_communication_analytics'), fn ($query) => $query->where('sender_user_id', $user->id))->get();
        $ids = $communications->pluck('id');
        $deliveries = DB::table('communication_deliveries')->where('school_id', $user->school_id)->whereIn('communication_id', $ids)->get();
        $inApp = $deliveries->where('channel', 'in_app');
        $read = DB::table('notifications')->where('school_id', $user->school_id)->whereIn('communication_id', $ids)->where('is_read', true)->count();
        $approvalDurations = $communications->filter(fn ($row) => $row->approved_at)->map(fn ($row) => CarbonImmutable::parse($row->created_at)->diffInMinutes(CarbonImmutable::parse($row->approved_at)));

        return ['total_communications' => $communications->count(), 'by_type' => $communications->countBy('communication_type'), 'by_priority' => $communications->countBy('priority'), 'by_status' => $communications->countBy('status'), 'deliveries_by_channel' => $deliveries->countBy('channel'), 'deliveries_by_status' => $deliveries->countBy('status'), 'read_rate' => $inApp->count() ? round($read * 100 / $inApp->count(), 2) : null, 'unread_count' => max(0, $inApp->count() - $read), 'missing_or_invalid_email_rate' => $deliveries->where('channel', 'email')->count() ? round($deliveries->where('channel', 'email')->where('status', 'skipped')->count() * 100 / $deliveries->where('channel', 'email')->count(), 2) : null, 'scheduled_count' => $communications->where('status', 'scheduled')->count(), 'cancelled_count' => $communications->where('status', 'cancelled')->count(), 'high_risk_count' => $communications->whereIn('risk_level', ['high', 'critical'])->count(), 'average_approval_minutes' => $approvalDurations->isNotEmpty() ? round($approvalDurations->avg(), 2) : null];
    }
}
