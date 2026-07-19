<?php

namespace App\Services\ParentPortal;

use App\Models\User;

class ParentPhaseTwoDashboardService
{
    public function __construct(
        private ParentPortalMobileService $phaseOne,
        private ParentPaymentService $payments,
        private ParentTaskService $tasks,
        private ParentPortalAnalyticsService $analytics,
    ) {}

    public function dashboard(User $user, ?string $learnerId): array
    {
        $base = $this->phaseOne->dashboard($user, $learnerId);
        $attempts = $this->payments->index($user, $learnerId);
        $tasks = $this->tasks->tasks($user);
        $analytics = $this->analytics->analytics($user);

        return $base + [
            'payment_provider' => $this->payments->health($user),
            'pending_payment' => $attempts->first(fn ($attempt) => in_array($attempt->status, ['pending', 'awaiting_customer', 'processing'], true)),
            'receipt_availability' => $attempts->where('status', 'completed')->whereNotNull('payment_id')->count(),
            'tasks' => array_slice($tasks, 0, 10), 'task_count' => count($tasks),
            'consent_requests' => data_get($analytics, 'consents.pending', 0),
            'appointment_updates' => collect(data_get($analytics, 'appointments', []))->sum(),
            'unread_conversations' => data_get($analytics, 'unread_messages', 0),
            'push_device_ready' => data_get($analytics, 'devices.push_enabled', 0) > 0,
            'last_synchronization_at' => data_get($analytics, 'sync.last_sync_at'),
        ];
    }
}
