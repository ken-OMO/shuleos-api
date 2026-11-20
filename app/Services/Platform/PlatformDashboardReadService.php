<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use Illuminate\Support\Facades\DB;

class PlatformDashboardReadService
{
    public function __construct(private AdministratorPortalAccessService $access) {}

    public function tasks(User $actor): array
    {
        $this->access->requirePlatform($actor, 'view_admin_tasks');
        $tasks = [];
        $providers = DB::table('administrator_provider_configurations')
            ->where('scope_type', 'platform')->whereNull('school_id')->where('enabled', true);

        if ((clone $providers)->where('secret_present', false)->exists()) {
            $tasks[] = ['key' => 'provider_secret_missing', 'type' => 'provider', 'severity' => 'critical', 'title' => 'Provider secret missing', 'explanation' => 'An enabled platform provider has no recorded credential.'];
        }
        if ((clone $providers)->where(fn ($q) => $q->whereNull('rotated_at')->orWhere('rotated_at', '<', now()->subYear()))->exists()) {
            $tasks[] = ['key' => 'provider_key_rotation_due', 'type' => 'provider', 'severity' => 'warning', 'title' => 'Provider key rotation review due', 'explanation' => 'An enabled platform provider has no recent recorded credential rotation.'];
        }
        if (DB::table('administrator_scheduler_heartbeats')->where(fn ($q) => $q->whereNull('last_completed_at')->orWhere('last_completed_at', '<', now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes', 15))))->exists()) {
            $tasks[] = ['key' => 'scheduler_stale', 'type' => 'scheduler', 'severity' => 'critical', 'title' => 'Scheduler heartbeat stale', 'explanation' => 'A monitored platform scheduler task has no recent completion.'];
        }
        if (DB::table('administrator_diagnostic_runs')->where('scope_type', 'platform')->whereNull('school_id')->where('status', 'critical')->where('created_at', '>=', now()->subDay())->exists()) {
            $tasks[] = ['key' => 'critical_diagnostic_failure', 'type' => 'diagnostic', 'severity' => 'critical', 'title' => 'Critical diagnostic failure', 'explanation' => 'A recent platform diagnostic reported a critical result.'];
        }
        if (! config('administrator_operations.restore_execution_enabled')) {
            $tasks[] = ['key' => 'restore_tooling_disabled', 'type' => 'recovery', 'severity' => 'warning', 'title' => 'Restore tooling disabled', 'explanation' => 'Restore execution remains disabled until trusted tooling is configured.'];
        }

        return $tasks;
    }

    public function alerts(User $actor): mixed
    {
        $this->access->requirePlatform($actor, 'view_admin_alerts');

        return DB::table('administrator_alerts as alerts')
            ->leftJoin('administrator_alert_states as states', fn ($join) => $join
                ->on('states.alert_id', '=', 'alerts.id')->where('states.user_id', $actor->id))
            ->whereNull('alerts.school_id')->where('alerts.status', 'open')
            ->select('alerts.id', 'alerts.type', 'alerts.severity', 'alerts.title', 'alerts.safe_message', 'alerts.created_at', 'states.state as user_state')
            ->orderByDesc('alerts.created_at')->orderBy('alerts.id')->limit(100)->get();
    }
}
