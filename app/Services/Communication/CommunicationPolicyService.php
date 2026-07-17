<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationPolicyService
{
    private const PRIORITY_WEIGHT = ['low' => 1, 'normal' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(private CommunicationAuditService $audit) {}

    public function policy(User $user, string $category): array
    {
        $row = DB::table('communication_policies')->where('school_id', $user->school_id)->where('category', $category)->first();
        if (! $row) {
            $id = (string) Str::uuid();
            DB::table('communication_policies')->insert(['id' => $id, 'school_id' => $user->school_id, 'category' => $category, 'enabled_channels' => json_encode(['in_app', 'email']), 'minimum_priority' => 'low', 'requires_approval' => in_array($category, ['emergency', 'finance'], true), 'approval_recipient_threshold' => 100, 'critical_recipient_threshold' => 1000, 'allowed_sender_roles' => null, 'allow_scheduling' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('communication_policies')->where('id', $id)->first();
        }

        return ['id' => $row->id, 'category' => $row->category, 'enabled_channels' => json_decode($row->enabled_channels, true), 'minimum_priority' => $row->minimum_priority, 'requires_approval' => (bool) $row->requires_approval, 'approval_recipient_threshold' => (int) $row->approval_recipient_threshold, 'critical_recipient_threshold' => (int) $row->critical_recipient_threshold, 'allowed_sender_roles' => $row->allowed_sender_roles ? json_decode($row->allowed_sender_roles, true) : null, 'allow_scheduling' => (bool) $row->allow_scheduling, 'default_expiry_days' => $row->default_expiry_days, 'sms_enabled' => false];
    }

    public function update(User $user, string $category, array $data): array
    {
        $policy = $this->policy($user, $category);
        $channels = array_values(array_unique($data['enabled_channels'] ?? $policy['enabled_channels']));
        if (array_diff($channels, ['in_app', 'email'])) {
            throw ValidationException::withMessages(['enabled_channels' => 'Only in-app and email channels are available in Phase 1.']);
        }
        DB::table('communication_policies')->where('id', $policy['id'])->update(['enabled_channels' => json_encode($channels), 'minimum_priority' => $data['minimum_priority'] ?? $policy['minimum_priority'], 'requires_approval' => $data['requires_approval'] ?? $policy['requires_approval'], 'approval_recipient_threshold' => $data['approval_recipient_threshold'] ?? $policy['approval_recipient_threshold'], 'critical_recipient_threshold' => $data['critical_recipient_threshold'] ?? $policy['critical_recipient_threshold'], 'allowed_sender_roles' => array_key_exists('allowed_sender_roles', $data) ? json_encode($data['allowed_sender_roles']) : json_encode($policy['allowed_sender_roles']), 'allow_scheduling' => $data['allow_scheduling'] ?? $policy['allow_scheduling'], 'default_expiry_days' => $data['default_expiry_days'] ?? $policy['default_expiry_days'], 'sms_enabled' => false, 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->audit->record($user, 'policy_changed', 'communication_policy', $policy['id'], null, ['category' => $category]);

        return $this->policy($user, $category);
    }

    public function validateChannels(array $requested, array $policy): array
    {
        $channels = array_values(array_unique($requested));
        if (! $channels || array_diff($channels, ['in_app', 'email'])) {
            throw ValidationException::withMessages(['channels' => 'Only in-app and email channels are available in Phase 1.']);
        }
        if (array_diff($channels, $policy['enabled_channels'])) {
            throw ValidationException::withMessages(['channels' => 'One or more requested channels are prohibited by school policy.']);
        }

        return $channels;
    }

    public function assertSenderAndPriority(User $user, array $policy, string $priority): void
    {
        if (! isset(self::PRIORITY_WEIGHT[$priority]) || self::PRIORITY_WEIGHT[$priority] < (self::PRIORITY_WEIGHT[$policy['minimum_priority']] ?? 1)) {
            throw ValidationException::withMessages(['priority' => 'Priority is below the minimum allowed by school policy.']);
        }

        if ($policy['allowed_sender_roles']) {
            $roleIds = DB::table('user_roles')
                ->where('user_id', $user->id)
                ->pluck('role_id')
                ->push($user->role_id)
                ->filter()
                ->unique()
                ->all();

            if (! array_intersect($roleIds, $policy['allowed_sender_roles'])) {
                throw ValidationException::withMessages(['sender' => 'Your role is not permitted to send this communication category.']);
            }
        }
    }
}
