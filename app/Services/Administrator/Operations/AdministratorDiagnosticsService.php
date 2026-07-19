<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorDiagnosticsService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function index(User $user, ?string $id = null): mixed
    {
        $scope = $this->access->school($user, 'view_operational_diagnostics');
        $query = DB::table('administrator_diagnostic_runs')->where(function ($query) use ($scope) {
            $query->where('school_id', $scope['school_id']);
            if ($scope['platform']) {
                $query->orWhere(fn ($platform) => $platform->whereNull('school_id')->where('scope_type', 'platform'));
            }
        });
        $query->select('id', 'scope_type', 'school_id', 'checks', 'status', 'safe_results', 'completed_at', 'created_at');

        return $id ? (array) $query->where('id', $id)->firstOrFail() : ['allowlisted_checks' => config('administrator_operations.diagnostic_checks'), 'recent' => $query->latest()->limit(50)->get()];
    }

    public function run(User $user, array $checks): array
    {
        $scope = $this->access->school($user, 'run_operational_diagnostics');
        $checks = array_values(array_unique($checks));
        if (! $checks || array_diff($checks, config('administrator_operations.diagnostic_checks'))) {
            throw ValidationException::withMessages(['checks' => 'Diagnostic check is not allowlisted.']);
        }
        $results = [];
        foreach ($checks as $check) {
            $results[$check] = $this->check($check);
        }
        $id = (string) Str::uuid();
        $status = collect($results)->contains('status', 'critical') ? 'critical' : (collect($results)->contains(fn ($result) => in_array($result['status'], ['warning', 'unavailable'], true)) ? 'warning' : 'healthy');
        DB::table('administrator_diagnostic_runs')->insert(['id' => $id, 'requested_by' => $user->id, 'scope_type' => 'school', 'school_id' => $scope['school_id'], 'checks' => json_encode($checks), 'status' => $status, 'safe_results' => json_encode($results), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_diagnostics_run', 'administrator_diagnostic_runs', $id, [], ['checks' => $checks, 'status' => $status]);

        return $this->index($user, $id);
    }

    public function notices(User $user, ?array $data = null, ?string $id = null, ?string $action = null): mixed
    {
        $this->access->platform($user, 'manage_system_notices');
        if ($data !== null) {
            $values = ['notice_type' => $data['notice_type'], 'audience' => $data['audience'], 'title' => strip_tags($data['title']), 'message' => strip_tags($data['message']), 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'updated_at' => now()];
            if ($id) {
                DB::table('administrator_system_notices')->where('id', $id)->where('status', 'draft')->update($values);
            } else {
                $id = (string) Str::uuid();
                DB::table('administrator_system_notices')->insert($values + ['id' => $id, 'status' => 'draft', 'created_by' => $user->id, 'created_at' => now()]);
            }
        }
        if ($action) {
            $status = $action === 'publish' ? 'published' : 'archived';
            DB::table('administrator_system_notices')->where('id', $id)->update(['status' => $status, 'published_by' => $action === 'publish' ? $user->id : null, 'published_at' => $action === 'publish' ? now() : null, 'updated_at' => now()]);
        }
        if ($id) {
            return (array) DB::table('administrator_system_notices')->where('id', $id)->select($this->noticeSafe())->firstOrFail();
        }

        return DB::table('administrator_system_notices')->select($this->noticeSafe())->latest()->limit(100)->get();
    }

    public function releases(User $user, bool $current = false): array
    {
        $this->access->school($user, 'view_release_metadata');
        $release = config('administrator_operations.release');
        $safe = ['version' => $release['version'] ?: 'unversioned', 'build' => $release['build'] ?: null, 'released_at' => $release['released_at'] ?: null, 'source' => 'safe_build_metadata'];

        return $current ? $safe : ['current' => $safe, 'history_available' => false];
    }

    public function settings(User $user, ?array $data = null): mixed
    {
        $this->access->platform($user, 'manage_platform_settings');
        if ($data !== null) {
            foreach ($data as $key => $value) {
                $this->saveSetting($user, $key, $value);
            }
        }
        $stored = DB::table('administrator_platform_settings')->pluck('value', 'key');

        return collect(config('administrator_operations.platform_settings'))->mapWithKeys(fn ($definition, $key) => [$key => ['value' => isset($stored[$key]) ? json_decode($stored[$key], true) : $definition['default'], 'type' => $definition['type']]])->all();
    }

    public function disasterRecovery(User $user): array
    {
        $this->access->platform($user, 'view_disaster_recovery_readiness');
        $latest = DB::table('administrator_backups')->where('scope_type', 'platform')->whereIn('status', ['completed', 'verified'])->latest('updated_at')->first();
        $verified = DB::table('administrator_backups')->where('scope_type', 'platform')->where('status', 'verified')->latest('verified_at')->first();
        $heartbeat = DB::table('administrator_scheduler_heartbeats')->where('task_key', 'admin_backup_dispatch')->first();
        $gaps = [];
        if (! $verified) {
            $gaps[] = 'no_verified_platform_backup';
        }
        if (! config('administrator_operations.restore_execution_enabled')) {
            $gaps[] = 'restore_tooling_disabled';
        }
        if (! $heartbeat?->last_completed_at || Carbon::parse($heartbeat->last_completed_at)->lt(now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes')))) {
            $gaps[] = 'backup_scheduler_stale_or_unknown';
        }
        $gaps[] = 'storage_redundancy_not_authoritatively_configured';
        $gaps[] = 'encryption_key_backup_policy_not_recorded';
        $gaps[] = 'recovery_contact_not_configured';
        $gaps[] = 'recovery_drill_not_recorded';

        return ['backup_provider_configured' => config('administrator_operations.backup_tooling') !== 'manifest_only', 'latest_backup' => $latest ? ['id' => $latest->id, 'status' => $latest->status, 'created_at' => $latest->created_at] : null, 'latest_verified_backup' => $verified ? ['id' => $verified->id, 'verified_at' => $verified->verified_at] : null, 'backup_age_hours' => $latest ? now()->diffInHours($latest->updated_at) : null, 'restore_tooling_enabled' => (bool) config('administrator_operations.restore_execution_enabled'), 'recoverability_verified' => $verified && ! $gaps, 'unresolved_critical_gaps' => $gaps];
    }

    private function check(string $check): array
    {
        try {
            return match ($check) {
                'database' => ['status' => DB::select('SELECT 1') ? 'healthy' : 'critical', 'hint' => 'Verify database connectivity.'],
                'migrations' => ['status' => Schema::hasTable('migrations') ? 'healthy' : 'critical', 'applied_count' => Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0],
                'cache' => $this->cacheCheck(),
                'queue' => ['status' => Schema::hasTable('jobs') ? 'healthy' : 'unavailable', 'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null],
                'scheduler' => ['status' => DB::table('administrator_scheduler_heartbeats')->where('last_completed_at', '>=', now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes')))->exists() ? 'healthy' : 'unavailable', 'hint' => 'A recent authoritative heartbeat is required.'],
                'storage' => ['status' => is_writable(storage_path('app')) ? 'healthy' : 'critical'],
                'storage_link' => ['status' => is_link(public_path('storage')) ? 'healthy' : 'warning'],
                'providers' => ['status' => DB::table('administrator_provider_configurations')->where('enabled', true)->where('secret_present', true)->exists() ? 'healthy' : 'warning'],
                'encryption' => ['status' => filled(config('app.key')) ? 'healthy' : 'critical'],
                'jwt' => ['status' => filled(config('jwt.secret')) ? 'healthy' : 'critical'],
                'disk' => ['status' => $this->diskPercentage() >= (int) config('administrator_operations.storage_warning_percentage') ? 'warning' : 'healthy', 'used_percentage' => $this->diskPercentage()],
                'failed_jobs' => ['status' => DB::table('failed_jobs')->count() >= (int) config('administrator_operations.failed_job_warning_threshold') ? 'warning' : 'healthy', 'count' => DB::table('failed_jobs')->count()],
            };
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'hint' => 'The local check could not establish an authoritative result.'];
        }
    }

    private function cacheCheck(): array
    {
        $key = 'admin-ops-diagnostic';
        Cache::put($key, true, 5);
        $ok = Cache::get($key) === true;
        Cache::forget($key);

        return ['status' => $ok ? 'healthy' : 'warning'];
    }

    private function diskPercentage(): int
    {
        $total = @disk_total_space(storage_path('app')) ?: 0;
        $free = @disk_free_space(storage_path('app')) ?: 0;

        return $total > 0 ? (int) round((($total - $free) / $total) * 100) : 100;
    }

    private function noticeSafe(): array
    {
        return ['id', 'notice_type', 'audience', 'title', 'message', 'status', 'starts_at', 'ends_at', 'published_at', 'created_at', 'updated_at'];
    }

    private function saveSetting(User $user, string $key, mixed $value): void
    {
        $definition = config('administrator_operations.platform_settings.'.$key);
        if (! $definition) {
            throw ValidationException::withMessages([$key => 'Platform setting is not allowlisted.']);
        } $this->validateSetting($key, $value, $definition);
        $existing = DB::table('administrator_platform_settings')->where('key', $key)->first();
        $version = ($existing->version ?? 0) + 1;
        $encoded = json_encode($value);
        if ($existing) {
            DB::table('administrator_platform_settings')->where('id', $existing->id)->update(['value' => $encoded, 'version' => $version, 'updated_by' => $user->id, 'updated_at' => now()]);
        } else {
            DB::table('administrator_platform_settings')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'value' => $encoded, 'version' => 1, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        } DB::table('administrator_platform_setting_history')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'old_value' => $existing?->value, 'new_value' => $encoded, 'version' => $version, 'actor_user_id' => $user->id, 'created_at' => now()]);
        $this->audit->record($user, 'administrator_platform_setting_updated', 'administrator_platform_settings', $existing?->id, [], ['key' => $key, 'version' => $version]);
    }

    private function validateSetting(string $key, mixed $value, array $definition): void
    {
        if ($definition['type'] === 'integer' && (! is_int($value) || $value < $definition['min'] || $value > $definition['max'])) {
            throw ValidationException::withMessages([$key => 'Integer setting is outside its allowed range.']);
        } if ($definition['type'] === 'array' && (! is_array($value) || array_diff($value, $definition['allowed']))) {
            throw ValidationException::withMessages([$key => 'Array setting contains an unsupported value.']);
        } if ($definition['type'] === 'timezone' && ! in_array($value, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages([$key => 'Invalid timezone.']);
        } if ($definition['type'] === 'string' && (! is_string($value) || strlen($value) > 20)) {
            throw ValidationException::withMessages([$key => 'Invalid string setting.']);
        }
    }
}
