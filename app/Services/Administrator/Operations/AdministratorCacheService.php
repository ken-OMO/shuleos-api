<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\Cache;

class AdministratorCacheService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function summary(User $user): array
    {
        $scope = $this->access->school($user, 'view_cache_operations');

        return ['status' => $this->healthy() ? 'healthy' : 'warning', 'groups' => config('administrator_operations.cache_groups'), 'scope' => $scope['operation_scope'], 'full_flush_available' => false, 'sessions_preserved' => true, 'rate_limits_preserved' => true];
    }

    public function preview(User $user, array $data): array
    {
        $scope = $this->access->school($user, 'clear_safe_cache_groups');
        $groups = $this->groups($data['groups']);

        return $this->access->preview($user, 'cache_clear', ['groups' => $groups, 'school_id' => $scope['school_id']], ['groups' => $groups, 'scope' => 'school', 'sessions_preserved' => true, 'rate_limits_preserved' => true], 'school', $scope['school_id']);
    }

    public function clear(User $user, array $data): array
    {
        $scope = $this->access->school($user, 'clear_safe_cache_groups');
        $groups = $this->groups($data['groups']);
        $request = ['groups' => $groups, 'school_id' => $scope['school_id']];
        $this->access->consumePreview($user, $data['preview_id'], 'cache_clear', $request);
        foreach ($groups as $group) {
            Cache::increment('admin-cache-version:school:'.$scope['school_id'].':'.$group);
        }
        $this->audit->record($user, 'administrator_cache_groups_invalidated', 'cache', null, [], ['groups' => $groups, 'scope' => 'school']);

        return ['status' => 'invalidated', 'groups' => $groups, 'sessions_preserved' => true, 'rate_limits_preserved' => true];
    }

    private function groups(array $groups): array
    {
        $groups = array_values(array_unique($groups));
        abort_if(! $groups || array_diff($groups, config('administrator_operations.cache_groups')), 422, 'Unsupported cache group.');

        return $groups;
    }

    private function healthy(): bool
    {
        try {
            $key = 'admin-ops-cache-health';
            Cache::put($key, true, 5);
            $ok = Cache::get($key) === true;
            Cache::forget($key);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }
}
