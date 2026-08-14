<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorRecoveryService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorMaintenanceService $maintenance, private AdministratorAuditService $audit) {}

    public function backups(User $user, ?string $id = null): mixed
    {
        $scope = $this->access->school($user, 'view_backup_operations');
        $query = DB::table('administrator_backups')->where(function ($query) use ($scope) {
            $query->where('school_id', $scope['school_id']);
            if ($scope['platform']) {
                $query->orWhere(fn ($platform) => $platform->whereNull('school_id')->where('scope_type', 'platform'));
            }
        });
        $query->select($this->backupSafe());

        return $id ? (array) $query->where('id', $id)->firstOrFail() : $query->latest()->limit(100)->get();
    }

    public function backupPreview(User $user, array $data): array
    {
        $scope = $this->backupScope($user, $data);
        $this->backupType($data['backup_type'], $scope['operation_scope']);
        $request = ['backup_type' => $data['backup_type'], 'scope_type' => $scope['operation_scope'], 'school_id' => $scope['target_school_id']];

        return $this->access->preview($user, 'backup', $request, ['backup_type' => $data['backup_type'], 'scope' => $scope['operation_scope'], 'tooling' => config('administrator_operations.backup_tooling'), 'background_only' => true, 'download_available' => false], $scope['operation_scope'], $scope['target_school_id']);
    }

    public function createBackup(User $user, array $data): array
    {
        $scope = $this->backupScope($user, $data);
        $this->backupType($data['backup_type'], $scope['operation_scope']);
        $request = ['backup_type' => $data['backup_type'], 'scope_type' => $scope['operation_scope'], 'school_id' => $scope['target_school_id']];
        $this->access->consumePreview($user, $data['preview_id'], 'backup', $request);
        $id = (string) Str::uuid();
        DB::table('administrator_backups')->insert(['id' => $id, 'school_id' => $scope['target_school_id'], 'scope_type' => $scope['operation_scope'], 'backup_type' => $data['backup_type'], 'status' => 'queued', 'tooling_available' => in_array($data['backup_type'], ['database_metadata', 'secure_file_manifest', 'configuration_manifest'], true), 'retention_until' => now()->addDays((int) config('administrator_operations.backup_retention_days')), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_backup_queued', 'administrator_backups', $id, [], ['backup_type' => $data['backup_type'], 'scope' => $scope['operation_scope']]);

        return $this->backups($user, $id);
    }

    public function verify(User $user, string $id): array
    {
        $this->access->school($user, 'verify_backups');
        $backup = (object) $this->backups($user, $id);
        if ($backup->status !== 'completed' || ! $backup->checksum) {
            throw ValidationException::withMessages(['backup' => 'Only completed backups with a checksum can be verified.']);
        }
        DB::table('administrator_backups')->where('id', $id)->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => $user->id, 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_backup_verified', 'administrator_backups', $id);

        return $this->backups($user, $id);
    }

    public function archive(User $user, string $id): array
    {
        $this->access->school($user, 'archive_backups');
        $backup = (object) $this->backups($user, $id);
        abort_unless(in_array($backup->status, ['completed', 'verified', 'expired', 'failed'], true), 409, 'Active backup cannot be archived.');
        DB::table('administrator_backups')->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_backup_archived', 'administrator_backups', $id);

        return $this->backups($user, $id);
    }

    public function dispatch(int $limit = 25): int
    {
        $count = 0;
        DB::table('administrator_backups')->where('status', 'queued')->orderBy('created_at')->limit(min($limit, 100))->get()->each(function ($backup) use (&$count) {
            if (! $backup->tooling_available) {
                DB::table('administrator_backups')->where('id', $backup->id)->update(['status' => 'failed', 'failure_code' => 'trusted_tooling_unavailable', 'updated_at' => now()]);

                return;
            }
            $manifest = ['backup_type' => $backup->backup_type, 'scope_type' => $backup->scope_type, 'school_id' => $backup->school_id, 'generated_at' => now()->toIso8601String(), 'contains_secrets' => false, 'restorable' => false];
            $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES);
            DB::table('administrator_backups')->where('id', $backup->id)->update(['status' => 'completed', 'safe_manifest' => $encoded, 'checksum' => hash('sha256', $encoded), 'size' => strlen($encoded), 'updated_at' => now()]);
            $count++;
        });

        return $count;
    }

    public function restores(User $user, ?string $id = null): mixed
    {
        $this->access->platform($user, 'view_restore_operations');
        $query = DB::table('administrator_restores')->select($this->restoreSafe());

        return $id ? (array) $query->where('id', $id)->firstOrFail() : $query->latest()->limit(100)->get();
    }

    public function restorePreview(User $user, array $data): array
    {
        $this->access->platform($user, 'create_restore_requests');
        $backup = DB::table('administrator_backups')->where('id', $data['backup_id'])->where('scope_type', 'platform')->where('status', 'verified')->first();
        if (! $backup) {
            throw ValidationException::withMessages(['backup_id' => 'A verified platform backup is required.']);
        }

        return $this->access->preview($user, 'restore', ['backup_id' => $backup->id, 'dry_run' => (bool) $data['dry_run']], ['backup_id' => $backup->id, 'dry_run' => (bool) $data['dry_run'], 'execution_enabled' => (bool) config('administrator_operations.restore_execution_enabled'), 'maintenance_required' => true, 'pre_restore_backup_required' => true], 'platform', null);
    }

    public function createRestore(User $user, array $data): array
    {
        $this->access->platform($user, 'create_restore_requests');
        $this->access->confirm($user, $data['confirmation'] ?? null, 'REQUEST PLATFORM RESTORE');
        if (! $data['dry_run']) {
            $this->access->platform($user, 'execute_restore_operations');
        }
        if (! $this->maintenance->activePlatform()) {
            throw ValidationException::withMessages(['maintenance' => 'Active platform maintenance is required.']);
        }
        $backup = DB::table('administrator_backups')->where('id', $data['backup_id'])->where('scope_type', 'platform')->where('status', 'verified')->first();
        $pre = DB::table('administrator_backups')->where('id', $data['pre_restore_backup_id'])->where('scope_type', 'platform')->where('status', 'verified')->first();
        if (! $backup || ! $pre) {
            throw ValidationException::withMessages(['backup' => 'Verified target and pre-restore backups are required.']);
        }
        $request = ['backup_id' => $backup->id, 'dry_run' => (bool) $data['dry_run']];
        $this->access->consumePreview($user, $data['preview_id'], 'restore', $request);
        if (! $data['dry_run'] && ! config('administrator_operations.restore_execution_enabled')) {
            throw ValidationException::withMessages(['restore' => 'Restore execution is disabled until trusted production tooling is configured.']);
        }
        $id = (string) Str::uuid();
        DB::table('administrator_restores')->insert(['id' => $id, 'backup_id' => $backup->id, 'pre_restore_backup_id' => $pre->id, 'status' => $data['dry_run'] ? 'validation_requested' : 'requested', 'reason' => strip_tags($data['reason']), 'dry_run' => (bool) $data['dry_run'], 'execution_enabled' => (bool) config('administrator_operations.restore_execution_enabled'), 'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_restore_requested', 'administrator_restores', $id, [], ['dry_run' => (bool) $data['dry_run'], 'execution_enabled' => (bool) config('administrator_operations.restore_execution_enabled')]);

        return $this->restores($user, $id);
    }

    public function cancelRestore(User $user, string $id): array
    {
        $this->access->platform($user, 'create_restore_requests');
        $restore = (object) $this->restores($user, $id);
        abort_unless(in_array($restore->status, ['requested', 'validation_requested'], true), 409, 'Restore can no longer be cancelled.');
        DB::table('administrator_restores')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

        $this->audit->record(
            $user,
            'administrator_restore_cancelled',
            'administrator_restores',
            $id
        );

        return $this->restores($user, $id);
    }

    private function backupScope(User $user, array $data): array
    {
        return $data['scope_type'] === 'platform' ? $this->access->platform($user, 'create_backups') : $this->access->school($user, 'create_backups');
    }

    private function backupType(string $type, string $scope): void
    {
        if (! in_array($type, config('administrator_operations.backup_types'), true) || ($type === 'full_platform' && $scope !== 'platform')) {
            throw ValidationException::withMessages(['backup_type' => 'Unsupported backup type for this scope.']);
        }
    }

    private function backupSafe(): array
    {
        return ['id', 'school_id', 'scope_type', 'backup_type', 'status', 'tooling_available', 'checksum', 'size', 'safe_manifest', 'failure_code', 'retention_until', 'verified_at', 'created_at', 'updated_at'];
    }

    private function restoreSafe(): array
    {
        return ['id', 'backup_id', 'pre_restore_backup_id', 'status', 'reason', 'dry_run', 'execution_enabled', 'cancelled_at', 'created_at', 'updated_at'];
    }
}
