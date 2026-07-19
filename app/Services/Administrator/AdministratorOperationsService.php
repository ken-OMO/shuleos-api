<?php

namespace App\Services\Administrator;

use App\Models\User;
use App\Services\Communication\CommunicationPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdministratorOperationsService
{
    private const DEVICE_TABLES = [
        'parent' => 'parent_portal_devices',
        'teacher' => 'teacher_portal_devices',
        'learner' => 'learner_portal_devices',
        'leadership' => 'leadership_portal_devices',
    ];

    private const REPORTS = ['user_summary', 'role_permission_summary', 'school_setup_status', 'subscription_status', 'audit_summary', 'security_summary', 'device_summary', 'import_summary', 'module_readiness', 'system_health_summary'];

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
        private AdministratorPortalService $portal,
        private AdministratorUserService $users,
        private CommunicationPolicyService $policies,
    ) {}

    public function audit(User $user, array $filters, ?string $id = null): mixed
    {
        $scope = $this->access->require($user, 'view_admin_audit');
        $query = DB::table('audit_logs')->where('school_id', $scope['school_id'])
            ->select('id', 'user_id', 'module', 'action', 'table_name', 'record_id', 'description', 'created_at');
        if ($id) {
            return $query->where('id', $id)->firstOrFail();
        }

        return $query->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['actor'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function auditSummary(User $user): array
    {
        $schoolId = $this->access->require($user, 'view_admin_audit')['school_id'];
        $base = DB::table('audit_logs')->where('school_id', $schoolId)->where('created_at', '>=', now()->subDays(30));

        return [
            'range_days' => 30,
            'total' => (clone $base)->count(),
            'by_module' => (clone $base)->selectRaw('module, COUNT(*) AS total')->groupBy('module')->pluck('total', 'module'),
            'by_action' => (clone $base)->selectRaw('action, COUNT(*) AS total')->groupBy('action')->orderByDesc('total')->limit(20)->pluck('total', 'action'),
        ];
    }

    public function security(User $user, string $view = 'summary'): mixed
    {
        $schoolId = $this->access->require($user, 'view_admin_security')['school_id'];
        $locked = DB::table('users')->where('school_id', $schoolId)->where('is_deleted', false)->where('account_locked_until', '>', now());
        if ($view === 'locked-users') {
            return $locked->select('id', 'username', 'first_name', 'last_name', 'failed_login_attempts', 'account_locked_until')->limit(100)->get();
        }
        if ($view === 'logins') {
            return DB::table('users')->where('school_id', $schoolId)->where('is_deleted', false)->select('id', 'username', 'last_login', 'last_failed_login', 'failed_login_attempts', 'account_locked_until')->latest('last_failed_login')->limit(100)->get();
        }
        if ($view === 'devices') {
            return $this->devices($user, []);
        }
        if ($view === 'sessions') {
            return ['tracking' => 'generation_based', 'active_session_count_available' => false, 'message' => 'JWT sessions can be revoked per user; individual session enumeration is not authoritative.'];
        }

        return [
            'locked_users' => (clone $locked)->count(),
            'failed_attempts_24h' => DB::table('users')->where('school_id', $schoolId)->where('last_failed_login', '>=', now()->subDay())->sum('failed_login_attempts'),
            'password_resets_required' => DB::table('users')->where('school_id', $schoolId)->whereNotNull('force_password_reset_at')->count(),
            'devices' => collect($this->deviceRows($schoolId))->count(),
            'explanation' => 'Indicators use failed-attempt, lock, reset and revocation state only.',
        ];
    }

    public function devices(User $user, array $filters, ?string $id = null): mixed
    {
        $schoolId = $this->access->require($user, 'view_school_devices')['school_id'];
        $rows = collect($this->deviceRows($schoolId));
        if ($id) {
            return $rows->firstWhere('id', $id) ?: abort(404);
        }

        return $rows->when($filters['role'] ?? null, fn ($items, $role) => $items->where('role', $role))->values();
    }

    public function revokeDevice(User $user, string $id): array
    {
        $schoolId = $this->access->require($user, 'revoke_school_devices')['school_id'];
        foreach (self::DEVICE_TABLES as $role => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('id', $id)->where('school_id', $schoolId)->first();
            if ($row) {
                DB::table($table)->where('id', $id)->update(['push_token_encrypted' => null, 'push_enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
                $this->audit->record($user, 'administrator_device_revoked', $table, $id, [], ['role' => $role]);

                return ['id' => $id, 'role' => $role, 'revoked' => true];
            }
        }
        abort(404);
    }

    public function communications(User $user, string $view, array $data = []): mixed
    {
        $this->access->require($user, $view === 'policies-update' ? 'manage_communication_policy' : 'view_provider_readiness');
        $schoolId = $user->school_id;
        if ($view === 'policies') {
            return DB::table('communication_policies')->where('school_id', $schoolId)->select('id', 'category', 'enabled_channels', 'minimum_priority', 'requires_approval', 'allow_scheduling', 'sms_enabled', 'updated_at')->get();
        }
        if ($view === 'policies-update') {
            return $this->policies->update($user, $data['category'], $data);
        }
        if ($view === 'failures') {
            return DB::table('communication_deliveries')->where('school_id', $schoolId)->whereIn('status', ['failed', 'bounced', 'complained'])->select('id', 'channel', 'status', 'failure_code', 'attempt_count', 'updated_at')->latest('updated_at')->limit(100)->get();
        }
        if ($view === 'suppressed') {
            return Schema::hasTable('communication_contact_health') ? DB::table('communication_contact_health')->where('school_id', $schoolId)->where('status', '<>', 'healthy')->select('id', 'channel', 'status', 'reason', 'updated_at')->limit(100)->get() : [];
        }
        if ($view === 'provider-health') {
            return $this->portal->providerReadiness($user)['communication'];
        }

        return DB::table('communications')->where('school_id', $schoolId)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
    }

    public function payments(User $user, string $view): array
    {
        $schoolId = $this->access->require($user, 'view_payment_reconciliation_summary')['school_id'];
        if ($view === 'provider-health') {
            return $this->portal->providerReadiness($user)['payment'];
        }
        if ($view === 'settings') {
            $row = Schema::hasTable('finance_settings') ? DB::table('finance_settings')->where('school_id', $schoolId)->first() : null;

            return ['configured' => (bool) $row, 'finance_enabled' => (bool) ($row->finance_enabled ?? $row), 'provider_mutation_available' => false];
        }

        return ['pending' => Schema::hasTable('parent_payment_attempts') ? DB::table('parent_payment_attempts')->where('school_id', $schoolId)->whereIn('status', ['pending', 'awaiting_customer'])->count() : 0, 'reconciliation_required' => Schema::hasTable('parent_payment_attempts') ? DB::table('parent_payment_attempts')->where('school_id', $schoolId)->where('status', 'reconciliation_required')->count() : 0];
    }

    public function health(User $user, ?string $component = null): array
    {
        $scope = $this->access->require($user, 'view_system_health');
        $checks = [
            'database' => $this->databaseHealth(),
            'queue' => ['status' => DB::table('failed_jobs')->count() > 0 ? 'warning' : 'healthy', 'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0, 'failed' => DB::table('failed_jobs')->count()],
            'scheduler' => ['status' => 'warning', 'heartbeat_available' => false],
            'storage' => ['status' => is_writable(storage_path('app')) ? 'healthy' : 'critical', 'private_storage_writable' => is_writable(storage_path('app'))],
            'cache' => $this->cacheHealth(),
        ];
        if (! $scope['platform']) {
            unset($checks['database']['migration_count']);
        }

        return $component ? ($checks[$component] ?? abort(404)) + ['component' => $component, 'checked_at' => now()->toIso8601String()] : ['status' => collect($checks)->contains('status', 'critical') ? 'critical' : (collect($checks)->contains('status', 'warning') ? 'warning' : 'healthy'), 'components' => $checks, 'checked_at' => now()->toIso8601String()];
    }

    public function preferences(User $user, ?array $data = null): mixed
    {
        $this->access->require($user, 'manage_admin_preferences');
        if ($data !== null) {
            $existing = DB::table('administrator_preferences')->where('user_id', $user->id)->first();
            $values = [
                'school_id' => $user->school_id,
                'dashboard_widgets' => json_encode($data['dashboard_widgets'] ?? []),
                'default_page_size' => $data['default_page_size'] ?? 25,
                'timezone' => $data['timezone'] ?? 'Africa/Nairobi',
                'language' => $data['language'] ?? 'en',
                'notification_preferences' => json_encode($data['notification_preferences'] ?? []),
                'digest_frequency' => $data['digest_frequency'] ?? 'daily',
                'default_audit_range_days' => $data['default_audit_range_days'] ?? 30,
                'preferred_dashboard' => $data['preferred_dashboard'] ?? 'school',
                'show_system_health' => $data['show_system_health'] ?? true,
                'version' => ($existing->version ?? 0) + 1,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('administrator_preferences')->where('id', $existing->id)->update($values);
            } else {
                DB::table('administrator_preferences')->insert($values + ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'created_at' => now()]);
            }
            $this->audit->record($user, 'administrator_preferences_updated', 'administrator_preferences', $user->id);
        }

        return DB::table('administrator_preferences')->where('user_id', $user->id)->select('dashboard_widgets', 'default_page_size', 'timezone', 'language', 'notification_preferences', 'digest_frequency', 'default_audit_range_days', 'preferred_dashboard', 'show_system_health', 'version')->first() ?: ['default_page_size' => 25, 'timezone' => 'Africa/Nairobi', 'language' => 'en', 'preferred_dashboard' => 'school', 'show_system_health' => true];
    }

    public function reports(User $user, ?string $type = null, bool $generate = false, array $filters = []): mixed
    {
        $this->access->require($user, $generate ? 'generate_admin_reports' : 'view_admin_reports');
        if ($type === null) {
            return ['allowlisted_reports' => self::REPORTS, 'recent' => DB::table('administrator_reports')->where('requested_by', $user->id)->select('id', 'report_type', 'scope', 'status', 'created_at', 'completed_at')->latest()->limit(50)->get()];
        }
        abort_unless(in_array($type, self::REPORTS, true), 422, 'Unsupported report type.');
        $preview = $this->reportPreview($user, $type);
        if (! $generate) {
            return ['report_type' => $type, 'preview' => $preview, 'bounded' => true];
        }
        $id = (string) Str::uuid();
        DB::table('administrator_reports')->insert(['id' => $id, 'school_id' => $user->school_id, 'requested_by' => $user->id, 'report_type' => $type, 'scope' => 'school', 'safe_filters' => json_encode(collect($filters)->only(['date_from', 'date_to', 'status'])->all()), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_report_requested', 'administrator_reports', $id, [], ['report_type' => $type]);

        return ['id' => $id, 'report_type' => $type, 'status' => 'queued', 'storage_available' => false];
    }

    public function tasks(User $user): array
    {
        $scope = $this->access->require($user, 'view_admin_tasks');
        $schoolId = $scope['school_id'];
        $tasks = [];
        $setup = $this->portal->academicSetup($user);
        foreach ($setup['missing'] as $missing) {
            $tasks[] = ['key' => 'missing_'.$missing, 'type' => 'setup', 'severity' => 'warning', 'title' => 'Configure '.Str::headline($missing), 'explanation' => 'The active school setup is incomplete.'];
        }
        if (($pending = DB::table('users')->where('school_id', $schoolId)->where('active', false)->where('is_deleted', false)->count()) > 0) {
            $tasks[] = ['key' => 'pending_user_activation', 'type' => 'users', 'severity' => 'warning', 'title' => 'Pending user activation', 'count' => $pending, 'explanation' => 'Inactive user accounts require review.'];
        }
        if (Schema::hasTable('administrator_backups')) {
            $latest = DB::table('administrator_backups')->where('school_id', $schoolId)->whereIn('status', ['completed', 'verified'])->latest('updated_at')->first();
            if (! $latest || Carbon::parse($latest->updated_at)->lt(now()->subDays(7))) {
                $tasks[] = ['key' => 'backup_overdue', 'type' => 'backup', 'severity' => 'warning', 'title' => 'Backup overdue', 'explanation' => 'No recent completed school backup manifest is recorded.'];
            }
            if (DB::table('administrator_backups')->where('school_id', $schoolId)->where('status', 'completed')->exists()) {
                $tasks[] = ['key' => 'backup_unverified', 'type' => 'backup', 'severity' => 'warning', 'title' => 'Backup verification pending', 'explanation' => 'A completed backup requires verification.'];
            }
        }
        if (Schema::hasTable('administrator_feature_flags') && DB::table('administrator_feature_flags')->where('scope_type', 'school')->where('scope_id', $schoolId)->where('status', 'active')->whereBetween('ends_at', [now(), now()->addDays(7)])->exists()) {
            $tasks[] = ['key' => 'feature_flag_expiring', 'type' => 'feature_flag', 'severity' => 'info', 'title' => 'Feature flag expiring', 'explanation' => 'A school feature flag expires within seven days.'];
        }
        if (Schema::hasTable('administrator_maintenance_windows') && DB::table('administrator_maintenance_windows')->where('school_id', $schoolId)->where('status', 'scheduled')->exists()) {
            $tasks[] = ['key' => 'maintenance_scheduled', 'type' => 'maintenance', 'severity' => 'info', 'title' => 'Maintenance scheduled', 'explanation' => 'A school maintenance window is scheduled.'];
        }
        if (Schema::hasTable('administrator_storage_records') && ($count = DB::table('administrator_storage_records')->where('school_id', $schoolId)->where('status', 'quarantined')->count())) {
            $tasks[] = ['key' => 'quarantine_backlog', 'type' => 'storage', 'severity' => 'warning', 'title' => 'Quarantine review required', 'count' => $count, 'explanation' => 'Quarantined file records require review.'];
        }
        if (Schema::hasTable('administrator_webhook_deliveries') && ($count = DB::table('administrator_webhook_deliveries')->join('administrator_webhooks', 'administrator_webhooks.id', '=', 'administrator_webhook_deliveries.webhook_id')->where('administrator_webhooks.school_id', $schoolId)->where('administrator_webhook_deliveries.status', 'failed')->count())) {
            $tasks[] = ['key' => 'webhook_failures', 'type' => 'webhook', 'severity' => 'warning', 'title' => 'Webhook delivery failures', 'count' => $count, 'explanation' => 'Webhook delivery records require review.'];
        }
        if (Schema::hasTable('administrator_api_keys') && DB::table('administrator_api_keys')->where('school_id', $schoolId)->whereNull('revoked_at')->whereBetween('expires_at', [now(), now()->addDays(14)])->exists()) {
            $tasks[] = ['key' => 'api_key_expiring', 'type' => 'api_key', 'severity' => 'warning', 'title' => 'API key expiring', 'explanation' => 'An API key expires within fourteen days.'];
        }
        if ($scope['platform'] && Schema::hasTable('administrator_scheduler_heartbeats')) {
            if (Schema::hasTable('administrator_provider_configurations') && DB::table('administrator_provider_configurations')->where('enabled', true)->where('secret_present', false)->exists()) {
                $tasks[] = ['key' => 'provider_secret_missing', 'type' => 'provider', 'severity' => 'critical', 'title' => 'Provider secret missing', 'explanation' => 'An enabled provider lacks required write-only credentials.'];
            }
            if (Schema::hasTable('administrator_provider_configurations') && DB::table('administrator_provider_configurations')->where('enabled', true)->where(fn ($query) => $query->whereNull('rotated_at')->orWhere('rotated_at', '<', now()->subYear()))->exists()) {
                $tasks[] = ['key' => 'provider_key_rotation_due', 'type' => 'provider', 'severity' => 'warning', 'title' => 'Provider key rotation review due', 'explanation' => 'An enabled provider credential has no recent recorded rotation.'];
            }
            if (DB::table('failed_jobs')->exists()) {
                $tasks[] = ['key' => 'failed_job', 'type' => 'queue', 'severity' => 'warning', 'title' => 'Failed jobs require review', 'count' => DB::table('failed_jobs')->count(), 'explanation' => 'One or more failed jobs are recorded.'];
            }
            if (DB::table('administrator_scheduler_heartbeats')->where(fn ($query) => $query->whereNull('last_completed_at')->orWhere('last_completed_at', '<', now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes', 15))))->exists()) {
                $tasks[] = ['key' => 'scheduler_stale', 'type' => 'scheduler', 'severity' => 'critical', 'title' => 'Scheduler heartbeat stale', 'explanation' => 'At least one monitored scheduler task lacks a recent authoritative heartbeat.'];
            }
            if (Schema::hasTable('administrator_diagnostic_runs') && DB::table('administrator_diagnostic_runs')->where('status', 'critical')->where('created_at', '>=', now()->subDay())->exists()) {
                $tasks[] = ['key' => 'critical_diagnostic_failure', 'type' => 'diagnostic', 'severity' => 'critical', 'title' => 'Critical diagnostic failure', 'explanation' => 'A recent authoritative diagnostic run reported a critical result.'];
            }
            if (! config('administrator_operations.restore_execution_enabled')) {
                $tasks[] = ['key' => 'restore_tooling_disabled', 'type' => 'recovery', 'severity' => 'warning', 'title' => 'Restore tooling disabled', 'explanation' => 'Restore execution remains disabled until trusted tooling is configured.'];
            }
        }

        return array_slice($tasks, 0, 100);
    }

    public function alerts(User $user): mixed
    {
        $scope = $this->access->require($user, 'view_admin_alerts');

        return DB::table('administrator_alerts as alerts')->leftJoin('administrator_alert_states as states', fn ($join) => $join->on('states.alert_id', '=', 'alerts.id')->where('states.user_id', $user->id))->where(fn ($query) => $query->where('alerts.school_id', $scope['school_id'])->when($scope['platform'], fn ($platform) => $platform->orWhereNull('alerts.school_id')))->where('alerts.status', 'open')->select('alerts.id', 'alerts.type', 'alerts.severity', 'alerts.title', 'alerts.safe_message', 'alerts.created_at', 'states.state as user_state')->latest('alerts.created_at')->limit(100)->get();
    }

    public function alertState(User $user, string $id, string $state): array
    {
        $scope = $this->access->require($user, 'acknowledge_admin_alerts');
        DB::table('administrator_alerts')->where('id', $id)->where(fn ($query) => $query->where('school_id', $scope['school_id'])->when($scope['platform'], fn ($platform) => $platform->orWhereNull('school_id')))->firstOrFail();
        DB::table('administrator_alert_states')->updateOrInsert(['alert_id' => $id, 'user_id' => $user->id], ['id' => (string) Str::uuid(), 'state' => $state, 'changed_at' => now()]);

        return ['id' => $id, 'state' => $state];
    }

    private function deviceRows(string $schoolId): array
    {
        $rows = [];
        foreach (self::DEVICE_TABLES as $role => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where('school_id', $schoolId)->select('id', 'user_id', 'platform', 'app_version', 'device_name', 'push_enabled', 'last_seen_at', 'revoked_at', 'created_at')->limit(250)->get() as $row) {
                $rows[] = ['id' => $row->id, 'user_id' => $row->user_id, 'role' => $role, 'platform' => $row->platform, 'app_version' => $row->app_version, 'device_name' => $row->device_name, 'push_enabled' => (bool) $row->push_enabled, 'last_seen_at' => $row->last_seen_at, 'revoked' => (bool) $row->revoked_at, 'created_at' => $row->created_at];
            }
        }

        return $rows;
    }

    private function databaseHealth(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'healthy', 'connectivity' => true, 'migration_count' => DB::table('migrations')->count()];
        } catch (\Throwable) {
            return ['status' => 'critical', 'connectivity' => false];
        }
    }

    private function cacheHealth(): array
    {
        try {
            $key = 'administrator-health:'.Str::uuid();
            Cache::put($key, true, 5);
            $healthy = Cache::get($key) === true;
            Cache::forget($key);

            return ['status' => $healthy ? 'healthy' : 'warning', 'connectivity' => $healthy];
        } catch (\Throwable) {
            return ['status' => 'warning', 'connectivity' => false];
        }
    }

    private function reportPreview(User $user, string $type): mixed
    {
        return match ($type) {
            'user_summary' => DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.school_id', $user->school_id)->selectRaw('roles.role_name, COUNT(*) AS total')->groupBy('roles.role_name')->pluck('total', 'roles.role_name'),
            'audit_summary' => $this->auditSummary($user),
            'security_summary' => $this->security($user),
            'device_summary' => collect($this->deviceRows($user->school_id))->countBy('role'),
            'school_setup_status' => $this->portal->academicSetup($user),
            'subscription_status' => $this->portal->subscription($user),
            'module_readiness' => $this->portal->modules($user),
            'system_health_summary' => $this->health($user),
            'import_summary' => DB::table('administrator_imports')->where('school_id', $user->school_id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            default => DB::table('roles')->leftJoin('users', 'users.role_id', '=', 'roles.id')->where(fn ($q) => $q->whereNull('roles.school_id')->orWhere('roles.school_id', $user->school_id))->selectRaw('roles.role_name, COUNT(users.id) AS users')->groupBy('roles.id', 'roles.role_name')->get(),
        };
    }
}
