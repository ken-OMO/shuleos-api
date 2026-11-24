<?php

namespace App\Services\Administrator;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\HumanResource\TeacherService;
use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class SchoolStaffAccessService
{
    public function __construct(private AuthContextService $auth, private AdministratorAuditService $audit,
        private AdministratorUserService $users, private SchoolBusinessWriteAccess $writes) {}

    private function school(User $actor): string
    {
        $context = $this->auth->resolve($actor);
        abort_unless($context['scope'] === 'school' && array_intersect($context['roles'], ['School Admin', 'Administrator', 'Director', 'School Owner'])
            && $this->auth->hasPermission($actor, 'update_school_users') && $this->auth->hasPermission($actor, 'assign_school_permissions'), 403);

        return $context['school_id'];
    }

    public function index(User $actor, int $page): array
    {
        $schoolId = $this->school($actor);
        $users = User::where('school_id', $schoolId)->where('is_deleted', false)->with('role')->orderBy('id')->paginate(25, ['*'], 'page', $page);

        return ['school_id' => $schoolId, 'page' => $users->currentPage(), 'last_page' => $users->lastPage(),
            'users' => $users->getCollection()->map(function ($user) {
                $roles = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $user->id)
                    ->where('roles.active', true)->where(fn ($q) => $q->whereNull('roles.school_id')->orWhere('roles.school_id', $user->school_id))->pluck('role_name')->all();

                return ['id' => $user->id, 'name' => trim($user->first_name.' '.$user->last_name), 'primary_role' => $user->role?->role_name,
                    'account_status' => $user->suspended_at ? 'suspended' : ($user->active ? 'active' : 'inactive'),
                    'additional_roles' => array_values(array_intersect($roles, ['Headteacher', 'School Secretary']))];
            })->all()];
    }

    public function setUpTeacher(User $actor, string $targetId, array $details): array
    {
        $schoolId = $this->school($actor);

        try {
            return $this->writes->run($schoolId, function () use ($actor, $targetId, $details, $schoolId) {
                $accounts = User::where('school_id', $schoolId)->whereIn('id', [$actor->id, $targetId])
                    ->where('is_deleted', false)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $freshActor = $accounts->get($actor->id);
                $target = $accounts->get($targetId);
                abort_unless($freshActor && $target && $target->active && ! $target->suspended_at, 404);
                $this->school($freshActor);
                abort_unless(strtolower((string) $target->role?->role_name) === 'teacher', 422, 'Select an active Teacher account.');

                $teachers = Teacher::withoutGlobalScopes()->where('school_id', $schoolId);
                $teacher = (clone $teachers)->where('user_id', $targetId)->lockForUpdate()->first();
                if ($teacher) {
                    abort_unless($teacher->active && ! $teacher->is_deleted
                        && $teacher->staff_no === $details['staff_no'] && $teacher->tsc_no === $details['tsc_no'],
                        409, 'An existing teacher profile must be reviewed before changing this linked account.');

                    return ['school_id' => $schoolId, 'user_id' => $targetId, 'teacher_id' => $teacher->id];
                }

                $teacher = $teachers->where('staff_no', $details['staff_no'])
                    ->where('tsc_no', $details['tsc_no'])->lockForUpdate()->first();
                if ($teacher) {
                    abort_unless($teacher->active && ! $teacher->is_deleted && $teacher->user_id === null, 409,
                        'The matching teacher profile is inactive or already linked.');
                    $teacher->update(['user_id' => $targetId]);
                } else {
                    $teacher = app(TeacherService::class)->create($details + ['school_id' => $schoolId, 'user_id' => $targetId]);
                }

                $this->audit->record($freshActor, 'teacher_profile_linked', 'teachers', $teacher->id,
                    ['user_id' => null], ['user_id' => $targetId]);

                return ['school_id' => $schoolId, 'user_id' => $targetId, 'teacher_id' => $teacher->id];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'The staff number or TSC number is already in use. Check the existing teacher profile.');
        }
    }

    public function change(User $actor, string $targetId, string $roleName, bool $assign): array
    {
        $schoolId = $this->school($actor);
        abort_unless(in_array($roleName, ['Headteacher', 'School Secretary'], true), 422);

        return $this->writes->run($schoolId, function () use ($actor, $targetId, $roleName, $assign, $schoolId) {
            $ids = array_values(array_unique([$actor->id, $targetId]));
            sort($ids);
            $locked = User::where('school_id', $schoolId)->whereIn('id', $ids)->where('is_deleted', false)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $freshActor = $locked->get($actor->id);
            $target = $locked->get($targetId);
            abort_unless($freshActor && $target && $target->active && ! $target->suspended_at, 404);
            $this->school($freshActor);
            $role = Role::whereNull('school_id')->where('system_role', true)->where('active', true)->where('role_name', $roleName)->lockForUpdate()->firstOrFail();
            if ($assign) {
                $this->users->invitationRole($freshActor, $role->id);
            }
            abort_if($target->role_id === $role->id, 422, 'This is the primary role.');
            $query = DB::table('user_roles')->where('user_id', $target->id)->where('role_id', $role->id);
            $exists = $query->exists();
            if ($exists !== $assign) {
                if ($assign) {
                    DB::table('user_roles')->insert(['user_id' => $target->id, 'role_id' => $role->id]);
                } else {
                    $query->delete();
                    $target->update(['auth_generation' => (int) $target->auth_generation + 1]);
                }
                $this->audit->record($freshActor, $assign ? 'staff_role_assigned' : 'staff_role_revoked', 'users', $target->id,
                    ['role' => $roleName, 'assigned' => $exists], ['role' => $roleName, 'assigned' => $assign]);
            }

            return ['school_id' => $schoolId, 'user_id' => $target->id, 'role' => $roleName, 'assigned' => $assign];
        });
    }
}
