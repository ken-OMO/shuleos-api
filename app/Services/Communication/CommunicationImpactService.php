<?php

namespace App\Services\Communication;

class CommunicationImpactService
{
    public function analyze(array $resolution, iterable $targets, string $priority, string $type, array $policy): array
    {
        $types = collect($targets)->map(fn ($target) => is_array($target) ? $target['target_type'] : $target->target_type);
        $reasons = [];
        $score = 0;
        if ($types->intersect(['entire_school', 'all_parents'])->isNotEmpty()) {
            $score = max($score, 3);
            $reasons[] = 'Mass school or parent audience.';
        }
        if ($types->contains('finance_balance_group')) {
            $score = max($score, 3);
            $reasons[] = 'Finance-balance targeting requires additional care.';
        }
        if (in_array($priority, ['high', 'critical'], true)) {
            $score = max($score, $priority === 'critical' ? 4 : 2);
            $reasons[] = ucfirst($priority).' priority.';
        }
        if ($type === 'emergency') {
            $score = 4;
            $reasons[] = 'Emergency communication.';
        }
        if ($resolution['unique_users'] >= ($policy['critical_recipient_threshold'] ?? 1000)) {
            $score = 4;
            $reasons[] = 'Critical recipient threshold reached.';
        } elseif ($resolution['unique_users'] >= ($policy['approval_recipient_threshold'] ?? 100)) {
            $score = max($score, 3);
            $reasons[] = 'Approval recipient threshold reached.';
        }
        if ($resolution['excluded']['missing_email'] + $resolution['excluded']['invalid_email'] > 0) {
            $score = max($score, 2);
            $reasons[] = 'Some recipients are not eligible for email.';
        }
        $levels = ['low', 'medium', 'high', 'critical'];
        $level = $levels[max(0, $score - 1)] ?? 'low';
        $approval = ($policy['requires_approval'] ?? false) || in_array($level, ['high', 'critical'], true);

        return ['risk_level' => $level, 'reasons' => $reasons ?: ['Scoped audience with supported channels.'], 'approval_required' => $approval, 'recommended_corrections' => $level === 'critical' ? ['Verify audience and use a separate approver before sending.'] : []];
    }
}
