<?php

namespace App\Services\Administrator;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministratorRolePermissionService
{
    private const PLATFORM = ['access_platform_administration', 'view_platform_dashboard', 'manage_school_lifecycle', 'onboard_schools', 'view_platform_subscriptions'];

    public function __construct(private AdministratorPortalAccessService $access, private AdministratorAuditService $audit) {}

    public function roles(User $user)
    {
        $scope = $this->access->require($user, 'view_roles_and_permissions');

        return Role::where('active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $scope['school_id']))->withCount('users')->orderBy('role_name')->get();
    }

    public function role(User $user, string $id): Role
    {
        $scope = $this->access->require($user, 'view_roles_and_permissions');

        return Role::whereKey($id)->where('active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $scope['school_id']))->withCount('users')->firstOrFail();
    }

    public function create(User $user, string $name): Role
    {
        $scope = $this->access->require($user, 'manage_school_roles');
        abort_if(Role::where('school_id', $scope['school_id'])->whereRaw('LOWER(role_name) = ?', [strtolower($name)])->exists(), 422, 'Role name already exists.');
        $role = Role::create(['id' => (string) Str::uuid(), 'role_name' => strip_tags($name), 'school_id' => $scope['school_id'], 'system_role' => false, 'active' => true, 'created_at' => now()]);
        $this->audit->record($user, 'administrator_role_created', 'roles', $role->id, [], ['role_name' => $role->role_name]);

        return $role;
    }

    public function update(User $user, string $id, string $name): Role
    {
        $this->access->require($user, 'manage_school_roles');
        $role = $this->role($user, $id);
        abort_if($role->system_role || ! $role->school_id, 409, 'System roles cannot be modified.');
        $old = $role->role_name;
        $role->update(['role_name' => strip_tags($name), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_role_updated', 'roles', $role->id, ['role_name' => $old], ['role_name' => $role->role_name]);

        return $role;
    }

    public function assign(User $user, string $id, array $permissionNames): Role
    {
        $scope = $this->access->require($user, 'assign_school_permissions');
        $role = $this->role($user, $id);
        abort_if($role->system_role || ! $role->school_id, 409, 'System role permissions cannot be changed here.');
        $permissions = DB::table('permissions')->whereIn('permission_name', $permissionNames)->get();
        abort_if($permissions->count() !== count(array_unique($permissionNames)), 422, 'Unknown permission supplied.');
        if (! $scope['platform'] && $permissions->pluck('permission_name')->intersect(self::PLATFORM)->isNotEmpty()) {
            throw new AuthorizationException('School roles cannot receive platform permissions.');
        }
        $actorNames = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_permissions.role_id', $user->role_id)->pluck('permissions.permission_name');
        if (! $scope['platform'] && $permissions->pluck('permission_name')->diff($actorNames)->isNotEmpty()) {
            throw new AuthorizationException('Administrators cannot grant permissions they do not possess.');
        }
        DB::transaction(function () use ($role, $permissions) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permission->id, 'created_at' => now()]);
            }
        });
        $this->audit->record($user, 'administrator_role_permissions_updated', 'roles', $role->id, [], ['permission_names' => $permissionNames]);

        return $role;
    }

    public function permissions(User $user)
    {
        $this->access->require($user, 'view_roles_and_permissions');

        return DB::table('permissions')->select('id', 'permission_name', 'module_name', 'description')->orderBy('module_name')->orderBy('permission_name')->get();
    }
}
