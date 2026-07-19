<?php

namespace App\Services\Administrator;

use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class AdministratorPortalAccessService
{
    private const PLATFORM_ROLES = ['platform_owner', 'platform_super_administrator'];

    private const ADMIN_ROLES = ['school_admin', 'administrator', 'principal', 'headteacher', 'platform_owner', 'platform_super_administrator', 'support_administrator', 'finance_administrator', 'finance_officer'];

    public function scope(User $user): array
    {
        if (! $user->id || ! $user->active || $user->is_deleted || ! $user->school_id || ($user->account_locked_until && $user->account_locked_until->isFuture())) {
            throw new AuthorizationException('Active administrator membership required.');
        }
        $role = $this->normalize($user->role?->role_name);
        if (! in_array($role, self::ADMIN_ROLES, true) || ! $this->has($user, 'access_administrator_portal')) {
            throw new AuthorizationException('Administrator permission required.');
        }
        $school = School::whereKey($user->school_id)->where('is_deleted', false)->first();
        if (! $school || in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true)) {
            throw new AuthorizationException('Administrator school membership is unavailable.');
        }
        $platform = in_array($role, self::PLATFORM_ROLES, true) && $this->has($user, 'access_platform_administration');

        return [
            'scope' => $platform ? 'platform' : ($role === 'support_administrator' ? 'support' : ($role === 'finance_administrator' || $role === 'finance_officer' ? 'finance' : 'school')),
            'school_id' => $user->school_id,
            'role' => $user->role?->role_name,
            'role_key' => $role,
            'platform' => $platform,
            'support' => $role === 'support_administrator',
            'finance' => in_array($role, ['finance_administrator', 'finance_officer'], true),
        ];
    }

    public function require(User $user, string $permission): array
    {
        $scope = $this->scope($user);
        if (! $this->has($user, $permission)) {
            throw new AuthorizationException('Permission denied.');
        }

        return $scope;
    }

    public function requirePlatform(User $user, string $permission = 'access_platform_administration'): array
    {
        $scope = $this->require($user, $permission);
        if (! $scope['platform']) {
            throw new AuthorizationException('Explicit platform administration scope required.');
        }

        return $scope;
    }

    public function school(User $user): School
    {
        $scope = $this->scope($user);

        return School::whereKey($scope['school_id'])->where('is_deleted', false)->firstOrFail();
    }

    public function platformSchool(User $user, string $schoolId): School
    {
        $this->requirePlatform($user);

        return School::whereKey($schoolId)->where('is_deleted', false)->firstOrFail();
    }

    public function user(User $actor, string $userId, bool $platformRoute = false): User
    {
        $scope = $platformRoute ? $this->requirePlatform($actor) : $this->scope($actor);
        $query = User::whereKey($userId)->where('is_deleted', false);
        if (! $platformRoute || ! $scope['platform']) {
            $query->where('school_id', $scope['school_id']);
        }
        $target = $query->with('role')->firstOrFail();
        if (! $scope['platform'] && in_array($this->normalize($target->role?->role_name), self::PLATFORM_ROLES, true)) {
            throw new AuthorizationException('Platform administrators are outside school administration scope.');
        }

        return $target;
    }

    public function has(User $user, string $permission): bool
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $user->role_id)->where('permissions.permission_name', $permission)->exists();
    }

    public function normalize(?string $role): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $role)));
    }
}
