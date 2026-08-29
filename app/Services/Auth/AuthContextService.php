<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuthContextService
{
    private const UNAVAILABLE_SCHOOL_STATES = [
        'suspended',
        'locked',
        'archived',
    ];

    private const PLATFORM_ROLES = [
        'Platform Owner',
        'Platform Super Administrator',
    ];

    public function resolve(User $authenticatedUser): array
    {
        $user = User::query()
            ->with(['school', 'role'])
            ->find($authenticatedUser->getKey());

        $this->assertAccessible($user);

        $roles = $this->roleRecords($user);

        $permissions = $this->permissionNamesForRoleIds(
            $roles->pluck('id')
        );

        $school = $user->school;

        $isPlatform = $this->isPlatformUser(
            $roles
        );

        return [
            'id' => (string) $user->id,

            'name' => collect([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ])
                ->filter(
                    fn ($part) => filled($part)
                )
                ->implode(' '),

            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,

            'status' => 'active',

            'scope' => $isPlatform
                ? 'platform'
                : 'school',

            'school_id' => $school
                ? (string) $school->id
                : null,

            'school' => $school
                ? [
                    'id' => (string) $school->id,
                    'name' => $school->school_name,
                    'short_name' => $school->short_name,
                    'status' => $school->lifecycle_state
                        ?: 'active',
                    'timezone' => $school->timezone
                        ?: config('app.timezone'),
                    'locale' => $school->locale
                        ?: config('app.locale'),
                ]
                : null,

            'roles' => $roles
                ->pluck('role_name')
                ->all(),

            'permissions' => $permissions->all(),

            'password_reset_required' => (bool) (
                $user->first_login
                || $user->force_password_reset_at
                || $user->temporary_password
            ),

            'account' => [
                'active' => true,
                'locked' => false,

                'requires_password_reset' => (bool) (
                    $user->first_login
                    || $user->force_password_reset_at
                    || $user->temporary_password
                ),
            ],
        ];
    }

    public function assertAccessible(?User $user): void
    {
        if (
            ! $user
            || ! $user->active
            || $user->is_deleted
            || $user->suspended_at
            || $user->account_locked_until?->isFuture()
        ) {
            throw new AuthenticationException(
                'Unauthenticated.'
            );
        }

        $roles = $this->roleRecords($user);

        if ($this->isPlatformUser($roles)) {
            /*
             * Platform identities must never belong to a school tenant.
             */
            if ($user->school_id !== null) {
                throw new AuthorizationException(
                    'Access is unavailable.'
                );
            }

            return;
        }

        /*
         * Every non-platform identity must belong to a valid school tenant.
         */
        if (! $user->school_id) {
            throw new AuthorizationException(
                'Access is unavailable.'
            );
        }

        $school = $user->relationLoaded('school')
            ? $user->school
            : $user->school()->first();

        if (
            ! $school
            || ! $school->active
            || $school->is_deleted
            || in_array(
                $school->lifecycle_state,
                self::UNAVAILABLE_SCHOOL_STATES,
                true
            )
        ) {
            throw new AuthorizationException(
                'Access is unavailable.'
            );
        }
    }

    public function permissionNames(User $user): Collection
    {
        $this->assertAccessible($user);

        return $this->permissionNamesForRoleIds(
            $this->roleRecords($user)->pluck('id')
        );
    }

    public function hasPermission(
        User $user,
        string $permission
    ): bool {
        return $this->permissionNames($user)
            ->contains($permission);
    }

    public function hasRole(
        User $user,
        string $role
    ): bool {
        $this->assertAccessible($user);

        return $this->roleRecords($user)
            ->contains(
                fn ($record) => hash_equals(
                    $record->role_name,
                    $role
                )
            );
    }

    private function roleRecords(User $user): Collection
    {
        $roleIds = DB::table('user_roles')
            ->where(
                'user_id',
                $user->id
            )
            ->pluck('role_id')
            ->push($user->role_id)
            ->filter()
            ->unique()
            ->values();

        return DB::table('roles')
            ->whereIn(
                'id',
                $roleIds
            )
            ->where(
                'active',
                true
            )
            ->where(function ($query) use ($user) {
                $query->whereNull('school_id');

                if ($user->school_id) {
                    $query->orWhere(
                        'school_id',
                        $user->school_id
                    );
                }
            })
            ->select(
                'id',
                'role_name'
            )
            ->get()
            ->unique('role_name')
            ->sortBy(
                'role_name',
                SORT_NATURAL | SORT_FLAG_CASE
            )
            ->values();
    }

    private function permissionNamesForRoleIds(
        Collection $roleIds
    ): Collection {
        if ($roleIds->isEmpty()) {
            return collect();
        }

        return DB::table('role_permissions')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id'
            )
            ->whereIn(
                'role_permissions.role_id',
                $roleIds
            )
            ->pluck(
                'permissions.permission_name'
            )
            ->filter()
            ->unique()
            ->sort(
                SORT_NATURAL | SORT_FLAG_CASE
            )
            ->values();
    }

    private function isPlatformUser(
        Collection $roles
    ): bool {
        return $roles->contains(
            fn ($role) => in_array(
                $role->role_name,
                self::PLATFORM_ROLES,
                true
            )
        );
    }
}
