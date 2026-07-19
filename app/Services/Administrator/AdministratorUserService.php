<?php

namespace App\Services\Administrator;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorUserService
{
    public function __construct(private AdministratorPortalAccessService $access, private AdministratorAuditService $audit) {}

    public function index(User $actor, array $filters)
    {
        $scope = $this->access->require($actor, 'view_school_users');

        return User::query()->where('school_id', $scope['school_id'])->where('is_deleted', false)->with('role:id,role_name')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('role', fn ($r) => $r->where('role_name', $role)))
            ->when(isset($filters['status']), fn ($q) => match ($filters['status']) {
                'active' => $q->where('active', true), 'suspended' => $q->where('active', false), 'locked' => $q->where('account_locked_until', '>', now()), default => $q
            })
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($x) => $x->whereRaw('LOWER(username) LIKE ?', ['%'.strtolower($search).'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.strtolower($search).'%'])->orWhereRaw('LOWER(first_name) LIKE ?', ['%'.strtolower($search).'%'])->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.strtolower($search).'%'])))
            ->orderBy('first_name')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function find(User $actor, string $id): User
    {
        $this->access->require($actor, 'view_school_users');

        return $this->access->user($actor, $id);
    }

    public function create(User $actor, array $data): User
    {
        $scope = $this->access->require($actor, 'create_school_users');
        $role = $this->role($actor, $data['role_id']);
        $duplicate = User::where('username', $data['username'])
            ->when(filled($data['email'] ?? null), fn ($query) => $query->orWhere('email', $data['email']))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['identity' => 'A user with this username or email already exists.']);
        }

        return DB::transaction(function () use ($actor, $scope, $role, $data) {
            $user = User::create([
                'id' => (string) Str::uuid(), 'school_id' => $scope['school_id'], 'role_id' => $role->id,
                'username' => trim($data['username']), 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'first_name' => strip_tags($data['first_name']), 'middle_name' => isset($data['middle_name']) ? strip_tags($data['middle_name']) : null,
                'last_name' => strip_tags($data['last_name']), 'password_hash' => Hash::make(Str::random(64)),
                'active' => false, 'first_login' => true, 'force_password_reset_at' => now(), 'auth_generation' => 1,
            ]);
            $this->audit->record($actor, 'administrator_user_created', 'users', $user->id, [], ['role_id' => $role->id, 'active' => false]);

            return $user->load('role:id,role_name');
        });
    }

    public function update(User $actor, string $id, array $data): User
    {
        $this->access->require($actor, 'update_school_users');
        $target = $this->access->user($actor, $id);
        if (isset($data['role_id'])) {
            $this->role($actor, $data['role_id']);
        }
        $allowed = ['role_id', 'username', 'first_name', 'middle_name', 'last_name', 'email', 'phone'];
        $old = $target->only($allowed);
        $target->update(collect($data)->only($allowed)->map(fn ($value) => is_string($value) ? strip_tags($value) : $value)->all());
        $this->audit->record($actor, 'administrator_user_updated', 'users', $target->id, $old, $target->fresh()->only($allowed));

        return $target->fresh()->load('role:id,role_name');
    }

    public function action(User $actor, string $id, string $action): User
    {
        $permission = match ($action) {
            'activate' => 'activate_school_users', 'suspend' => 'suspend_school_users', 'unlock' => 'unlock_school_users', 'force-password-reset' => 'force_school_user_password_reset', 'revoke-sessions' => 'revoke_school_user_sessions', 'revoke-devices' => 'revoke_school_user_devices', default => throw new AuthorizationException('Unsupported user action.')
        };
        $this->access->require($actor, $permission);
        $target = $this->access->user($actor, $id);
        if ($action === 'suspend') {
            $this->assertNotLastAdministrator($actor, $target);
            abort_if($target->id === $actor->id, 409, 'Administrators cannot suspend their own account.');
            $target->update(['active' => false, 'suspended_at' => now(), 'auth_generation' => $target->auth_generation + 1]);
        } elseif ($action === 'activate') {
            $target->update(['active' => true, 'suspended_at' => null]);
        } elseif ($action === 'unlock') {
            $target->update(['failed_login_attempts' => 0, 'account_locked_until' => null]);
        } elseif ($action === 'force-password-reset') {
            $target->update(['force_password_reset_at' => now(), 'first_login' => true, 'auth_generation' => $target->auth_generation + 1]);
        } elseif ($action === 'revoke-sessions') {
            $target->update(['auth_generation' => $target->auth_generation + 1]);
        } elseif ($action === 'revoke-devices') {
            $this->revokeDevices($target);
        }
        $this->audit->record($actor, 'administrator_user_'.$action, 'users', $target->id, [], ['action' => $action]);

        return $target->fresh()->load('role:id,role_name');
    }

    private function role(User $actor, string $roleId): Role
    {
        $scope = $this->access->scope($actor);
        $role = Role::whereKey($roleId)->where('active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $scope['school_id']))->firstOrFail();
        $platform = in_array($this->access->normalize($role->role_name), ['platform_owner', 'platform_super_administrator'], true);
        if ($platform && ! $scope['platform']) {
            throw new AuthorizationException('School administrators cannot assign platform roles.');
        }
        $actorPermissions = DB::table('role_permissions')->where('role_id', $actor->role_id)->pluck('permission_id');
        $outside = DB::table('role_permissions')->where('role_id', $role->id)->whereNotIn('permission_id', $actorPermissions)->exists();
        if ($outside && ! $scope['platform']) {
            throw new AuthorizationException('Administrators cannot assign a role with permissions they do not possess.');
        }

        return $role;
    }

    private function assertNotLastAdministrator(User $actor, User $target): void
    {
        if (! in_array($this->access->normalize($target->role?->role_name), ['school_admin', 'administrator', 'principal', 'headteacher'], true)) {
            return;
        }
        $count = User::where('school_id', $actor->school_id)->where('active', true)->where('is_deleted', false)->whereHas('role', fn ($q) => $q->whereIn('role_name', ['School Admin', 'Administrator', 'Principal', 'Headteacher']))->count();
        abort_if($count <= 1, 409, 'The last active school administrator cannot be suspended.');
    }

    private function revokeDevices(User $target): void
    {
        foreach (['parent_portal_devices', 'teacher_portal_devices', 'learner_portal_devices', 'leadership_portal_devices'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->where('school_id', $target->school_id)->where('user_id', $target->id)->update(['push_token_encrypted' => null, 'push_enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
