<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationSandboxSmokeService
{
    private const PREFIX = 'SANDBOX-COMMUNICATION-SMOKE-';

    private const REQUIRED_PERMISSIONS = [
        'approve_communications',
        'schedule_communications',
        'send_emergency_broadcasts',
        'send_schoolwide_communications',
        'view_communication_analytics',
    ];

    public function __construct(
        private CommunicationService $communications,
        private EmergencyCommunicationService $emergency,
        private CommunicationAnalyticsService $analytics,
        private AdvancedCommunicationAnalyticsService $advancedAnalytics,
        private ScheduledCommunicationDispatchService $scheduled,
        private SmsWalletService $wallets,
    ) {}

    public function run(string $email, bool $cleanup): array
    {
        $this->assertAllowed($email);

        $prefix = self::PREFIX.now()->format('ymdHis').Str::upper(Str::random(2));
        $fixtures = $this->createFixtures($prefix, $email);
        $sender = User::withoutGlobalScopes()->findOrFail($fixtures['leadership_user_id']);
        $approver = User::withoutGlobalScopes()->findOrFail($fixtures['teacher_user_id']);
        $walletBefore = $this->walletSnapshot($fixtures['school_id']);
        $analyticsBefore = $this->analyticsSnapshot($sender);
        $communicationIds = [];
        $previews = [];
        $scheduledDispatches = 0;

        try {
            [$communicationIds['announcement'], $previews['announcement']] = $this->standardWorkflow($sender, $approver, [
                'communication_type' => 'announcement',
                'category' => 'urgent_announcement',
                'priority' => 'normal',
                'subject' => $prefix.' Announcement',
                'body' => 'Temporary communication smoke-test announcement.',
                'channels' => ['in_app', 'email'],
                'targets' => $this->parentTarget($fixtures['parent_user_id']),
            ]);

            [$communicationIds['fee_reminder'], $previews['fee_reminder']] = $this->standardWorkflow($sender, $approver, [
                'communication_type' => 'finance_notice',
                'category' => 'fee_reminder',
                'priority' => 'normal',
                'subject' => $prefix.' Fee Reminder',
                'body' => 'Temporary communication smoke-test fee reminder.',
                'channels' => ['in_app', 'email'],
                'targets' => $this->parentTarget($fixtures['parent_user_id']),
            ]);

            $emergencyData = [
                'subject' => $prefix.' Emergency Notification',
                'body' => 'Temporary communication smoke-test emergency notification.',
                'reason' => 'Local communication provider and workflow smoke test.',
                'emergency_category' => 'sandbox_smoke',
                'attempt_sms' => false,
                'targets' => $this->parentTarget($fixtures['parent_user_id']),
            ];
            $previews['emergency'] = $this->emergency->preview($sender, $emergencyData);
            $emergencyCommunication = $this->emergency->send($sender, $emergencyData, $previews['emergency']['confirmation_token']);
            $communicationIds['emergency'] = $emergencyCommunication->id;
            $this->approveIfRequired($sender, $approver, $emergencyCommunication->id);
            $this->communications->send($sender, $emergencyCommunication->id);

            $scheduledDefinition = [
                'communication_type' => 'general',
                'category' => 'general',
                'priority' => 'normal',
                'subject' => $prefix.' Scheduled Notification',
                'body' => 'Temporary immediately-due scheduled communication smoke test.',
                'channels' => ['in_app', 'email'],
                'targets' => $this->parentTarget($fixtures['parent_user_id']),
            ];
            $scheduledCommunication = $this->communications->create($sender, $scheduledDefinition);
            $communicationIds['scheduled_notification'] = $scheduledCommunication->id;
            $previews['scheduled_notification'] = $this->communications->preview($sender, $scheduledCommunication->id);
            $this->communications->submit($sender, $scheduledCommunication->id);
            $this->approveIfRequired($sender, $approver, $scheduledCommunication->id);
            $this->communications->schedule($sender, $scheduledCommunication->id, now()->addSecond()->toDateTimeString());
            usleep(1100000);
            $scheduledDispatches = $this->scheduled->dispatchDue($this->communications, $scheduledCommunication->id);

            $report = $this->report($prefix, $fixtures, $communicationIds, $previews, $analyticsBefore, $walletBefore, $scheduledDispatches, $sender);
        } catch (\Throwable $exception) {
            $report = [
                'result' => 'FAIL',
                'prefix' => $prefix,
                'fixture_ids' => $fixtures,
                'communication_ids' => $communicationIds,
                'failed_checks' => ['workflow_exception: '.mb_substr($exception->getMessage(), 0, 500)],
            ];
        }

        if ($cleanup) {
            $report['cleanup'] = $this->cleanup($fixtures, $prefix, $communicationIds);
        } else {
            $report['cleanup'] = ['requested' => false, 'fixtures_retained' => true];
        }

        return $report;
    }

    public function assertAllowed(string $email): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            throw ValidationException::withMessages(['environment' => 'Communication sandbox smoke is blocked outside local, testing, or staging.']);
        }

        if (! config('communication.sandbox_smoke_enabled', false)) {
            throw ValidationException::withMessages(['feature_flag' => 'COMMUNICATION_SANDBOX_SMOKE_ENABLED=true is required.']);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'A valid --email address is required.']);
        }
    }

    public function cleanup(array $fixtures, string $prefix, array $communicationIds = []): array
    {
        $schoolId = $fixtures['school_id'] ?? null;
        $school = $schoolId && Schema::hasTable('schools')
            ? DB::table('schools')->where('id', $schoolId)->where('school_name', 'like', self::PREFIX.'%')->first()
            : null;

        if (! $school || ! str_starts_with($prefix, self::PREFIX)) {
            return ['requested' => true, 'fixtures_retained' => true, 'reason' => 'Prefix ownership could not be proven.'];
        }

        if (Schema::hasTable('communications')) {
            $discoveredIds = DB::table('communications')
                ->where('school_id', $schoolId)
                ->where('subject', 'like', $prefix.'%')
                ->pluck('id')
                ->all();
            $communicationIds = array_values(array_unique(array_merge(array_values($communicationIds), $discoveredIds)));
        }

        $deliveryIds = Schema::hasTable('communication_deliveries')
            ? DB::table('communication_deliveries')->where('school_id', $schoolId)->whereIn('communication_id', array_values($communicationIds))->pluck('id')
            : collect();
        $emailDeliveries = Schema::hasTable('communication_deliveries')
            ? DB::table('communication_deliveries')->where('school_id', $schoolId)->whereIn('communication_id', array_values($communicationIds))->where('channel', 'email')->get()
            : collect();
        $unsafeHistory = $emailDeliveries
            ->whereNotIn('status', ['pending', 'queued', 'skipped'])
            ->isNotEmpty();
        $queuedJobsUnproven = $emailDeliveries->where('status', 'queued')->contains(function ($delivery) {
            return ! Schema::hasTable('jobs') || ! DB::table('jobs')->where('payload', 'like', '%'.$delivery->id.'%')->exists();
        });

        if ($unsafeHistory || $queuedJobsUnproven) {
            DB::table('schools')->where('id', $schoolId)->update(['active' => false, 'updated_at' => now()]);

            return [
                'requested' => true,
                'fixtures_retained' => true,
                'reason' => $unsafeHistory
                    ? 'Provider delivery and audit history was retained; sandbox school was deactivated.'
                    : 'Queued-job isolation could not be proven; sandbox school and history were retained and deactivated.',
            ];
        }

        $unrelatedTenantDeliveries = Schema::hasTable('communication_deliveries') && DB::table('communication_deliveries')
            ->where('school_id', $schoolId)
            ->whereNotIn('communication_id', array_values($communicationIds))
            ->exists();
        if ($unrelatedTenantDeliveries) {
            return ['requested' => true, 'fixtures_retained' => true, 'reason' => 'Unexpected tenant delivery records prevented isolated cleanup.'];
        }

        $deletedJobs = 0;
        if (Schema::hasTable('jobs')) {
            foreach ($deliveryIds as $deliveryId) {
                $deletedJobs += DB::table('jobs')->where('payload', 'like', '%'.$deliveryId.'%')->delete();
            }
        }

        DB::transaction(function () use ($fixtures, $schoolId, $communicationIds, $deliveryIds) {
            $communicationIds = array_values($communicationIds);
            $this->deleteWhereIn('communication_delivery_status_history', 'delivery_id', $deliveryIds);
            $this->deleteWhereIn('communication_provider_webhook_events', 'delivery_id', $deliveryIds);
            $this->deleteWhereIn('sms_usage_records', 'delivery_id', $deliveryIds);
            $this->deleteWhereIn('notifications', 'communication_id', $communicationIds);
            $this->deleteWhereIn('communication_deliveries', 'communication_id', $communicationIds);
            $this->deleteWhereIn('communication_recipient_snapshots', 'communication_id', $communicationIds);
            $this->deleteWhereIn('communication_targets', 'communication_id', $communicationIds);
            $this->deleteWhereIn('communication_audit_logs', 'communication_id', $communicationIds);
            $this->deleteWhereIn('communication_emergency_confirmations', 'school_id', [$schoolId]);
            $this->deleteWhereIn('communications', 'id', $communicationIds);
            $this->deleteWhereIn('communication_preferences', 'school_id', [$schoolId]);
            $this->deleteWhereIn('communication_contact_health', 'school_id', [$schoolId]);
            $this->deleteWhereIn('communication_branding', 'school_id', [$schoolId]);
            $this->deleteWhereIn('sms_credit_transactions', 'school_id', [$schoolId]);
            $this->deleteWhereIn('school_sms_wallets', 'school_id', [$schoolId]);
            $this->deleteWhereIn('communication_policies', 'school_id', [$schoolId]);
            $this->deleteWhereIn('teacher_assignments', 'id', [$fixtures['teacher_assignment_id'] ?? null]);
            $this->deleteWhereIn('learner_parents', 'id', [$fixtures['learner_parent_link_id'] ?? null]);
            $this->deleteWhereIn('learners', 'id', [$fixtures['learner_id'] ?? null]);
            $this->deleteWhereIn('parents', 'id', [$fixtures['parent_id'] ?? null]);
            $this->deleteWhereIn('teachers', 'id', [$fixtures['teacher_id'] ?? null]);
            $this->deleteWhereIn('role_permissions', 'role_id', [$fixtures['leadership_role_id'] ?? null, $fixtures['teacher_role_id'] ?? null]);
            $this->deleteWhereIn('users', 'id', [$fixtures['leadership_user_id'] ?? null, $fixtures['teacher_user_id'] ?? null, $fixtures['parent_user_id'] ?? null]);
            $this->deleteWhereIn('roles', 'id', [$fixtures['leadership_role_id'] ?? null, $fixtures['teacher_role_id'] ?? null, $fixtures['parent_role_id'] ?? null]);
            $this->deleteWhereIn('terms', 'id', [$fixtures['term_id'] ?? null]);
            $this->deleteWhereIn('academic_years', 'id', [$fixtures['academic_year_id'] ?? null]);
            $this->deleteWhereIn('streams', 'id', [$fixtures['stream_id'] ?? null]);
            $this->deleteWhereIn('grades', 'id', [$fixtures['grade_id'] ?? null]);
            $this->deleteWhereIn('learning_areas', 'id', [$fixtures['learning_area_id'] ?? null]);
            $this->deleteWhereIn('schools', 'id', [$schoolId]);
        });

        return ['requested' => true, 'fixtures_retained' => false, 'deleted_school_id' => $schoolId, 'deleted_queue_jobs' => $deletedJobs];
    }

    private function createFixtures(string $prefix, string $email): array
    {
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            throw ValidationException::withMessages(['email' => 'The supplied email is already assigned to a user; use an isolated smoke-test address.']);
        }

        $ids = collect([
            'school', 'leadership_role', 'teacher_role', 'parent_role', 'leadership_user', 'teacher_user', 'parent_user', 'teacher', 'parent', 'grade', 'stream', 'learner', 'learner_parent_link', 'learning_area', 'academic_year', 'term', 'teacher_assignment',
        ])->mapWithKeys(fn ($key) => [$key.'_id' => (string) Str::uuid()])->all();

        DB::transaction(function () use ($ids, $prefix, $email) {
            $shortPrefix = 'S-'.Str::after($prefix, self::PREFIX);
            DB::table('schools')->insert(['id' => $ids['school_id'], 'school_name' => $prefix, 'school_code' => 'SCS'.now()->format('ymdHis').Str::upper(Str::random(3)), 'active' => true, 'is_deleted' => false]);
            DB::table('roles')->insert([
                ['id' => $ids['leadership_role_id'], 'role_name' => $shortPrefix.'-L'],
                ['id' => $ids['teacher_role_id'], 'role_name' => $shortPrefix.'-T'],
                ['id' => $ids['parent_role_id'], 'role_name' => $shortPrefix.'-P'],
            ]);

            $password = Hash::make(Str::random(48));
            DB::table('users')->insert([
                ['id' => $ids['leadership_user_id'], 'school_id' => $ids['school_id'], 'role_id' => $ids['leadership_role_id'], 'username' => $prefix.'-leader', 'password_hash' => $password, 'email' => null, 'first_name' => 'Sandbox', 'last_name' => 'Leader', 'active' => true, 'is_deleted' => false],
                ['id' => $ids['teacher_user_id'], 'school_id' => $ids['school_id'], 'role_id' => $ids['teacher_role_id'], 'username' => $prefix.'-teacher', 'password_hash' => $password, 'email' => null, 'first_name' => 'Sandbox', 'last_name' => 'Teacher', 'active' => true, 'is_deleted' => false],
                ['id' => $ids['parent_user_id'], 'school_id' => $ids['school_id'], 'role_id' => $ids['parent_role_id'], 'username' => $prefix.'-parent', 'password_hash' => $password, 'email' => $email, 'first_name' => 'Sandbox', 'last_name' => 'Parent', 'active' => true, 'is_deleted' => false],
            ]);

            $permissions = DB::table('permissions')->whereIn('permission_name', self::REQUIRED_PERMISSIONS)->pluck('id', 'permission_name');
            if ($permissions->count() !== count(self::REQUIRED_PERMISSIONS)) {
                throw ValidationException::withMessages(['permissions' => 'Required Phase 1/2 communication permissions are missing.']);
            }
            foreach ([$ids['leadership_role_id'], $ids['teacher_role_id']] as $roleId) {
                foreach ($permissions as $permissionId) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }

            DB::table('teachers')->insert(['id' => $ids['teacher_id'], 'school_id' => $ids['school_id'], 'user_id' => $ids['teacher_user_id'], 'staff_no' => 'SMOKE-'.now()->format('His'), 'active' => true, 'is_deleted' => false]);
            DB::table('parents')->insert(['id' => $ids['parent_id'], 'school_id' => $ids['school_id'], 'user_id' => $ids['parent_user_id'], 'first_name' => 'Sandbox', 'last_name' => 'Parent', 'phone' => '+254700000000', 'email' => $email, 'relationship' => 'Parent', 'active' => true, 'is_deleted' => false]);
            DB::table('grades')->insert(['id' => $ids['grade_id'], 'school_id' => $ids['school_id'], 'grade_name' => $prefix.'-G', 'grade_order' => 1, 'active' => true]);
            DB::table('streams')->insert(['id' => $ids['stream_id'], 'school_id' => $ids['school_id'], 'grade_id' => $ids['grade_id'], 'stream_name' => $prefix.'-S', 'active' => true]);
            DB::table('learners')->insert(['id' => $ids['learner_id'], 'school_id' => $ids['school_id'], 'admission_no' => 'SMOKE-'.now()->format('YmdHis'), 'first_name' => 'Sandbox', 'last_name' => 'Learner', 'grade_id' => $ids['grade_id'], 'stream_id' => $ids['stream_id'], 'active' => true, 'portal_enabled' => true, 'is_deleted' => false]);
            DB::table('learner_parents')->insert(['id' => $ids['learner_parent_link_id'], 'learner_id' => $ids['learner_id'], 'parent_id' => $ids['parent_id'], 'relationship' => 'Parent', 'is_primary_contact' => true, 'active' => true, 'portal_enabled' => true, 'receives_email' => true, 'receives_sms' => false, 'is_deleted' => false]);
            DB::table('learning_areas')->insert(['id' => $ids['learning_area_id'], 'learning_area_name' => $prefix.'-A', 'short_name' => 'SMOKE', 'active' => true]);
            DB::table('academic_years')->insert(['id' => $ids['academic_year_id'], 'school_id' => $ids['school_id'], 'year_name' => $shortPrefix.'-Y', 'start_date' => today()->startOfYear(), 'end_date' => today()->endOfYear(), 'active' => true]);
            DB::table('terms')->insert(['id' => $ids['term_id'], 'school_id' => $ids['school_id'], 'academic_year_id' => $ids['academic_year_id'], 'term_name' => $shortPrefix.'-M', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'active' => true]);
            DB::table('teacher_assignments')->insert(['id' => $ids['teacher_assignment_id'], 'school_id' => $ids['school_id'], 'teacher_id' => $ids['teacher_id'], 'learning_area_id' => $ids['learning_area_id'], 'grade_id' => $ids['grade_id'], 'stream_id' => $ids['stream_id'], 'academic_year_id' => $ids['academic_year_id'], 'term_id' => $ids['term_id'], 'is_class_teacher' => true, 'active' => true, 'is_deleted' => false]);

            foreach (['urgent_announcement' => false, 'fee_reminder' => true, 'emergency' => true, 'general' => false] as $category => $requiresApproval) {
                DB::table('communication_policies')->insert(['id' => (string) Str::uuid(), 'school_id' => $ids['school_id'], 'category' => $category, 'enabled_channels' => json_encode(['in_app', 'email']), 'minimum_priority' => 'low', 'requires_approval' => $requiresApproval, 'approval_recipient_threshold' => 100, 'critical_recipient_threshold' => 1000, 'allow_scheduling' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $ids;
    }

    private function standardWorkflow(User $sender, User $approver, array $definition): array
    {
        $communication = $this->communications->create($sender, $definition);
        $preview = $this->communications->preview($sender, $communication->id);
        $this->communications->submit($sender, $communication->id);
        $this->approveIfRequired($sender, $approver, $communication->id);
        $this->communications->send($sender, $communication->id);

        return [$communication->id, $preview];
    }

    private function approveIfRequired(User $sender, User $approver, string $communicationId): void
    {
        if (DB::table('communications')->where('id', $communicationId)->value('status') === 'pending_approval') {
            $this->communications->approve($approver, $communicationId);
        }

        abort_unless(DB::table('communications')->where('id', $communicationId)->where('sender_user_id', $sender->id)->where('status', 'approved')->exists(), 409, 'Smoke communication did not reach approved status.');
    }

    private function report(string $prefix, array $fixtures, array $communicationIds, array $previews, array $analyticsBefore, array $walletBefore, int $scheduledDispatches, User $sender): array
    {
        $ids = array_values($communicationIds);
        $communications = DB::table('communications')->where('school_id', $fixtures['school_id'])->whereIn('id', $ids)->get();
        $emailDeliveries = DB::table('communication_deliveries')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->where('channel', 'email')->get();
        $inAppCounts = DB::table('notifications')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->selectRaw('communication_id, COUNT(*) AS total')->groupBy('communication_id')->pluck('total', 'communication_id');
        $auditCount = DB::table('communication_audit_logs')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->count();
        $walletAfter = $this->walletSnapshot($fixtures['school_id']);
        $analyticsAfter = $this->analyticsSnapshot($sender);
        $smsDeliveryCount = DB::table('communication_deliveries')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->where('channel', 'sms')->count();
        $smsUsageCount = DB::table('sms_usage_records')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->count();
        $smsJobCount = 0;
        if (Schema::hasTable('jobs')) {
            $smsDeliveryIds = DB::table('communication_deliveries')->where('school_id', $fixtures['school_id'])->whereIn('communication_id', $ids)->where('channel', 'sms')->pluck('id');
            foreach ($smsDeliveryIds as $deliveryId) {
                $smsJobCount += DB::table('jobs')->where('payload', 'like', '%'.$deliveryId.'%')->where('payload', 'like', '%DeliverCommunicationSms%')->count();
            }
        }
        $unhealthyContacts = DB::table('communication_contact_health')->where('school_id', $fixtures['school_id'])->whereIn('status', ['warning', 'invalid', 'suppressed', 'opted_out'])->count();
        $checks = [
            'four_communications_created' => $communications->count() === 4,
            'each_preview_resolved_one_recipient' => collect($previews)->every(fn ($preview) => ($preview['unique_users_count'] ?? 0) === 1),
            'one_in_app_notification_per_communication' => collect($ids)->every(fn ($id) => (int) ($inAppCounts[$id] ?? 0) === 1),
            'email_delivery_per_communication' => $emailDeliveries->count() === 4,
            'audit_events_created' => $auditCount > 0,
            'analytics_total_increased' => data_get($analyticsAfter, 'phase_one.total_communications') === data_get($analyticsBefore, 'phase_one.total_communications') + 4,
            'scheduled_message_dispatched' => $scheduledDispatches === 1 && $communications->firstWhere('id', $communicationIds['scheduled_notification'])?->status === 'sent',
            'contact_health_not_unhealthy' => $unhealthyContacts === 0,
            'sms_delivery_not_created' => $smsDeliveryCount === 0,
            'sms_job_not_created' => $smsJobCount === 0,
            'sms_usage_not_created' => $smsUsageCount === 0,
            'sms_wallet_unchanged' => $walletBefore === $walletAfter,
        ];
        $failed = collect($checks)->filter(fn ($passed) => ! $passed)->keys()->values()->all();

        return [
            'result' => $failed ? 'FAIL' : 'PASS',
            'prefix' => $prefix,
            'fixture_ids' => $fixtures,
            'communications' => $communications->map(fn ($communication) => ['id' => $communication->id, 'type' => $communication->communication_type, 'category' => $communication->category, 'recipient_count' => $communication->recipient_count, 'status' => $communication->status])->values(),
            'email_deliveries' => $emailDeliveries->map(fn ($delivery) => ['communication_id' => $delivery->communication_id, 'database_status' => $delivery->status, 'provider_state' => $this->providerState($delivery->status), 'provider' => $delivery->provider])->values(),
            'in_app_notification_counts' => $inAppCounts,
            'audit_count' => $auditCount,
            'analytics_before' => $analyticsBefore,
            'analytics_after' => $analyticsAfter,
            'scheduled_dispatch' => ['dispatched' => $scheduledDispatches, 'communication_id' => $communicationIds['scheduled_notification']],
            'contact_health' => ['unhealthy_count' => $unhealthyContacts, 'result' => $unhealthyContacts ? 'provider_failure_or_suppression_reported' : 'healthy_or_not_yet_reported'],
            'sms' => ['delivery_count' => $smsDeliveryCount, 'queued_job_count' => $smsJobCount, 'usage_record_count' => $smsUsageCount],
            'sms_wallet_before' => $walletBefore,
            'sms_wallet_after' => $walletAfter,
            'checks' => $checks,
            'failed_checks' => $failed,
            'operator_notice' => 'Database state cannot prove inbox delivery. Verify the supplied inbox and the configured provider dashboard; provider state is reported only as queued, accepted, or failed.',
        ];
    }

    private function analyticsSnapshot(User $user): array
    {
        return ['phase_one' => $this->analytics->summary($user), 'advanced' => $this->advancedAnalytics->summary($user)];
    }

    private function walletSnapshot(string $schoolId): array
    {
        $wallet = $this->wallets->wallet($schoolId);

        return ['balance_credits' => (int) $wallet->balance_credits, 'transaction_count' => DB::table('sms_credit_transactions')->where('school_id', $schoolId)->count()];
    }

    private function parentTarget(string $parentUserId): array
    {
        return [['target_type' => 'explicit_user', 'options' => ['user_ids' => [$parentUserId]]]];
    }

    private function providerState(string $status): string
    {
        return match ($status) {
            'accepted', 'sent', 'delivered', 'opened', 'clicked' => 'accepted',
            'failed', 'bounced', 'complained' => 'failed',
            default => 'queued',
        };
    }

    private function deleteWhereIn(string $table, string $column, iterable $values): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $values = collect($values)->filter()->values();
        if ($values->isNotEmpty()) {
            DB::table($table)->whereIn($column, $values)->delete();
        }
    }
}
