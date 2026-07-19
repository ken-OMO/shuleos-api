<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorMaintenanceService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function index(User $user): mixed
    {
        $scope = $this->access->school($user, 'manage_school_maintenance');

        return DB::table('administrator_maintenance_windows')->where(fn ($q) => $q->where('school_id', $scope['school_id'])->orWhere(fn ($p) => $p->whereNull('school_id')->where('scope_type', 'platform')))->select($this->safe())->latest()->limit(100)->get();
    }

    public function preview(User $user, array $data): array
    {
        $scope = $this->access->scope($user, $data['scope_type'], 'manage_school_maintenance', 'manage_platform_maintenance');
        $this->validateWindow($data);

        return $this->access->preview($user, 'maintenance', $this->request($data, $scope), ['scope' => $scope['operation_scope'], 'reason' => strip_tags($data['reason']), 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'sessions_preserved' => true, 'data_preserved' => true], $scope['operation_scope'], $scope['target_school_id']);
    }

    public function schedule(User $user, array $data): array
    {
        $scope = $this->access->scope($user, $data['scope_type'], 'manage_school_maintenance', 'manage_platform_maintenance');
        $this->validateWindow($data);
        $request = $this->request($data, $scope);
        $this->access->consumePreview($user, $data['preview_id'], 'maintenance', $request);
        $id = (string) Str::uuid();
        DB::table('administrator_maintenance_windows')->insert(['id' => $id, 'school_id' => $scope['target_school_id'], 'scope_type' => $scope['operation_scope'], 'status' => 'scheduled', 'reason' => strip_tags($data['reason']), 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->history($user, $id, null, 'scheduled', $data['reason']);

        return $this->show($user, $id);
    }

    public function action(User $user, array $data, string $action): array
    {
        $window = $this->showObject($user, $data['maintenance_id']);
        $window->scope_type === 'platform' ? $this->access->platform($user, 'manage_platform_maintenance') : $this->access->school($user, 'manage_school_maintenance');
        if ($action === 'activate') {
            $this->access->confirm($user, $data['confirmation'] ?? null, 'ACTIVATE MAINTENANCE');
        }
        $to = match ($action) {
            'activate' => 'active', 'deactivate' => 'completed', 'cancel' => 'cancelled', default => abort(404)
        };
        $allowed = ['scheduled' => ['active', 'cancelled'], 'active' => ['completed']];
        if (! in_array($to, $allowed[$window->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid maintenance state transition.']);
        }
        $updates = ['status' => $to, 'updated_at' => now()];
        if ($to === 'active') {
            $updates['activated_by'] = $user->id;
        }
        if ($to === 'completed') {
            $updates['completed_by'] = $user->id;
        }
        DB::table('administrator_maintenance_windows')->where('id', $window->id)->update($updates);
        $this->history($user, $window->id, $window->status, $to, $data['reason'] ?? null);

        return $this->show($user, $window->id);
    }

    public function activePlatform(): bool
    {
        return DB::table('administrator_maintenance_windows')->where('scope_type', 'platform')->where('status', 'active')->exists();
    }

    private function show(User $user, string $id): array
    {
        return (array) $this->showObject($user, $id);
    }

    private function showObject(User $user, string $id): object
    {
        $scope = $this->access->school($user, 'manage_school_maintenance');

        return DB::table('administrator_maintenance_windows')->where('id', $id)->where(fn ($q) => $q->where('school_id', $scope['school_id'])->orWhere('scope_type', 'platform'))->select($this->safe())->firstOrFail();
    }

    private function safe(): array
    {
        return ['id', 'scope_type', 'school_id', 'status', 'reason', 'starts_at', 'ends_at', 'created_at', 'updated_at'];
    }

    private function request(array $data, array $scope): array
    {
        return ['scope_type' => $scope['operation_scope'], 'school_id' => $scope['target_school_id'], 'reason' => trim($data['reason']), 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null];
    }

    private function validateWindow(array $data): void
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Maintenance reason is required.']);
        }
        if (isset($data['starts_at'], $data['ends_at'])) {
            $starts = Carbon::parse($data['starts_at']);
            $ends = Carbon::parse($data['ends_at']);
            if ($ends->lte($starts)) {
                throw ValidationException::withMessages(['ends_at' => 'Maintenance end must follow its start.']);
            }
            if ($ends->gt($starts->copy()->addDays(7))) {
                throw ValidationException::withMessages(['ends_at' => 'A maintenance window cannot exceed seven days.']);
            }
        }
    }

    private function history(User $user, string $id, ?string $from, string $to, ?string $reason): void
    {
        DB::table('administrator_maintenance_history')->insert(['id' => (string) Str::uuid(), 'maintenance_id' => $id, 'actor_user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'safe_reason' => $reason ? Str::limit(strip_tags($reason), 1000, '') : null, 'created_at' => now()]);
        $this->audit->record($user, 'administrator_maintenance_'.$to, 'administrator_maintenance_windows', $id, ['status' => $from], ['status' => $to]);
    }
}
