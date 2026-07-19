<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorFeatureFlagService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function index(User $user): mixed
    {
        $scope = $this->access->school($user, 'manage_school_feature_flags');
        $platform = $user->role && in_array(strtolower($user->role->role_name), ['platform owner', 'platform super administrator'], true);

        return DB::table('administrator_feature_flags')->where('status', '<>', 'archived')->where(fn ($q) => $q->where(fn ($s) => $s->where('scope_type', 'school')->where('scope_id', $scope['school_id']))->when($platform, fn ($s) => $s->orWhere('scope_type', 'platform')))->select($this->safe())->orderBy('key')->get()->map(fn ($flag) => $this->effective($flag));
    }

    public function show(User $user, string $id): array
    {
        return $this->effective($this->owned($user, $id));
    }

    public function save(User $user, array $data, ?string $id = null): array
    {
        $definition = config('administrator_operations.feature_flags.'.$data['key']);
        if (! $definition) {
            throw ValidationException::withMessages(['key' => 'Feature flag key is not allowlisted.']);
        }
        $scope = $this->access->scope($user, $data['scope_type'], 'manage_school_feature_flags', 'manage_platform_feature_flags');
        if ($data['scope_type'] === 'school' && ! ($definition['school_overridable'] ?? false)) {
            throw ValidationException::withMessages(['key' => 'This feature flag is platform-only.']);
        }
        $values = ['key' => $data['key'], 'description' => $definition['description'], 'scope_type' => $scope['operation_scope'], 'scope_id' => $scope['target_school_id'], 'enabled' => (bool) ($data['enabled'] ?? false), 'rollout_percentage' => $data['rollout_percentage'] ?? null, 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'metadata' => json_encode(collect($data['metadata'] ?? [])->only(['note', 'ticket'])->all()), 'status' => 'active', 'updated_by' => $user->id, 'updated_at' => now()];
        $flag = $id ? $this->owned($user, $id) : null;
        if (! $flag && DB::table('administrator_feature_flags')->where('key', $data['key'])->where('scope_type', $scope['operation_scope'])->where('scope_id', $scope['target_school_id'])->exists()) {
            throw ValidationException::withMessages(['key' => 'Feature flag already exists for this scope.']);
        }
        if ($flag) {
            DB::table('administrator_feature_flags')->where('id', $id)->update($values);
        } else {
            $id = (string) Str::uuid();
            DB::table('administrator_feature_flags')->insert($values + ['id' => $id, 'created_by' => $user->id, 'created_at' => now()]);
        }
        $this->history($user, $id, $flag ? 'updated' : 'created');

        return $this->show($user, $id);
    }

    public function action(User $user, string $id, string $action): array
    {
        $flag = $this->owned($user, $id);
        $permission = $flag->scope_type === 'platform' ? 'manage_platform_feature_flags' : 'manage_school_feature_flags';
        $flag->scope_type === 'platform' ? $this->access->platform($user, $permission) : $this->access->school($user, $permission);
        $updates = $action === 'archive' ? ['status' => 'archived'] : ['enabled' => $action === 'enable'];
        DB::table('administrator_feature_flags')->where('id', $id)->update($updates + ['updated_by' => $user->id, 'updated_at' => now()]);
        $this->history($user, $id, $action);

        return $this->show($user, $id);
    }

    public function resolve(string $key, string $schoolId, ?string $subjectId = null): bool
    {
        $now = now();
        $flags = DB::table('administrator_feature_flags')->where('key', $key)->where('status', 'active')->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->where(fn ($q) => $q->where(fn ($s) => $s->where('scope_type', 'school')->where('scope_id', $schoolId))->orWhere('scope_type', 'platform'))->get();
        $flag = $flags->first(fn ($item) => $item->scope_type === 'school') ?: $flags->first(fn ($item) => $item->scope_type === 'platform');

        if (! ($flag?->enabled ?? false)) {
            return false;
        }

        $percentage = $flag->rollout_percentage;
        if ($percentage === null || (int) $percentage === 100) {
            return true;
        }
        if ((int) $percentage === 0 || blank($subjectId)) {
            return false;
        }

        $bucket = (int) sprintf('%u', crc32($key.':'.$schoolId.':'.$subjectId)) % 100;

        return $bucket < (int) $percentage;
    }

    private function owned(User $user, string $id): object
    {
        $scope = $this->access->school($user, 'manage_school_feature_flags');
        $query = DB::table('administrator_feature_flags')->where('id', $id);
        if (! $user->role || ! in_array(strtolower($user->role->role_name), ['platform owner', 'platform super administrator'], true)) {
            $query->where('scope_type', 'school')->where('scope_id', $scope['school_id']);
        }

        return $query->firstOrFail();
    }

    private function history(User $user, string $id, string $action): void
    {
        $flag = DB::table('administrator_feature_flags')->where('id', $id)->first();
        $snapshot = collect((array) $flag)->only(['key', 'description', 'scope_type', 'scope_id', 'enabled', 'rollout_percentage', 'starts_at', 'ends_at', 'status'])->all();
        DB::table('administrator_feature_flag_history')->insert(['id' => (string) Str::uuid(), 'flag_id' => $id, 'actor_user_id' => $user->id, 'action' => $action, 'safe_snapshot' => json_encode($snapshot), 'created_at' => now()]);
        $this->audit->record($user, 'administrator_feature_flag_'.$action, 'administrator_feature_flags', $id, [], ['key' => $flag->key, 'scope' => $flag->scope_type], $flag->scope_type === 'school' ? $flag->scope_id : null);
    }

    private function effective(object $flag): array
    {
        $timeActive = (! $flag->starts_at || now()->gte($flag->starts_at)) && (! $flag->ends_at || now()->lt($flag->ends_at));

        return collect((array) $flag)->only($this->safe())->merge(['effective' => (bool) $flag->enabled && $flag->status === 'active' && $timeActive, 'entitlement_override' => false])->all();
    }

    private function safe(): array
    {
        return ['id', 'key', 'description', 'scope_type', 'scope_id', 'enabled', 'rollout_percentage', 'starts_at', 'ends_at', 'metadata', 'status', 'created_at', 'updated_at'];
    }
}
