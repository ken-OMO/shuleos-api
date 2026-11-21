<?php

namespace App\Services\Administrator;

use App\Mail\SchoolUserInvitationMail;
use App\Models\AuthenticationChallenge;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolUserInvitationService
{
    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorUserService $users,
        private AdministratorAuditService $audit,
        private SchoolBusinessWriteAccess $writes,
    ) {}

    public function scope(User $actor): string
    {
        $scope = $this->access->require($actor, 'create_school_users');
        $this->access->require($actor, 'activate_school_users');
        if ($scope['platform'] || ! $scope['school_id'] || ! in_array($scope['role_key'], ['school_admin', 'administrator', 'director', 'school_owner'], true)) {
            throw new AuthorizationException('A school administrator is required for invitations.');
        }

        return $scope['school_id'];
    }

    public function index(User $actor): array
    {
        $schoolId = $this->scope($actor);

        return $this->writes->run($schoolId, function () use ($schoolId) {
            $school = School::findOrFail($schoolId);

            return [
                'school_id' => $schoolId, 'school_code' => $school->school_code,
                'pending' => User::query()->where('school_id', $schoolId)->where('requires_school_code', true)
                    ->where('is_deleted', false)->where('temporary_password', true)->whereNull('activated_at')
                    ->orderBy('created_at')->limit(100)->get(['id', 'first_name', 'last_name', 'email'])
                    ->map(fn ($user) => $user->only(['id', 'first_name', 'last_name', 'email']))->all(),
            ];
        });
    }

    public function roles(User $actor): array
    {
        $schoolId = $this->scope($actor);

        return $this->writes->run($schoolId, function () use ($actor, $schoolId) {
            return Role::query()->where('active', true)
                ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId))
                ->orderBy('role_name')->get()->filter(function ($role) use ($actor) {
                    try {
                        $this->users->invitationRole($actor, $role->id);

                        return true;
                    } catch (AuthorizationException) {
                        return false;
                    }
                })->map(fn ($role) => ['id' => $role->id, 'name' => $role->role_name])->values()->all();
        });
    }

    public function invite(User $actor, array $data): array
    {
        $schoolId = $this->scope($actor);
        try {
            return $this->writes->run($schoolId, function () use ($actor, $schoolId, $data) {
                $this->users->invitationRole($actor, $data['role_id']);
                $email = strtolower(trim($data['email']));
                if (User::query()->where('school_id', $schoolId)
                    ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])->orWhereRaw('LOWER(username) = ?', [$email]))->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an account in your school.']);
                }
                $password = Str::random(32).'#9Aa';
                $user = User::create([
                    'id' => (string) Str::uuid(), 'school_id' => $schoolId, 'role_id' => $data['role_id'],
                    'username' => $email, 'email' => $email,
                    'first_name' => trim(strip_tags($data['first_name'])), 'last_name' => trim(strip_tags($data['last_name'])),
                    'password_hash' => Hash::make($password), 'active' => true, 'first_login' => true,
                    'temporary_password' => true, 'temporary_password_expires_at' => now()->addDay(),
                    'force_password_reset_at' => now(), 'auth_generation' => 1, 'invitation_generation' => 1,
                    'requires_school_code' => true,
                ]);
                $this->stampInvitation($user);
                $this->audit->record($actor, 'school_user_invited', 'users', $user->id, [], ['role_id' => $user->role_id]);
                $this->schedule($user, $password);

                return ['id' => $user->id, 'status' => 'pending_activation'];
            });
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            throw ValidationException::withMessages(['email' => 'This email already has an account in your school.']);
        }
    }

    public function resend(User $actor, string $id): array
    {
        $schoolId = $this->scope($actor);

        return $this->writes->run($schoolId, function () use ($actor, $schoolId, $id) {
            $user = User::query()->whereKey($id)->where('school_id', $schoolId)->where('requires_school_code', true)
                ->where('is_deleted', false)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && ! $user->suspended_at && ! $user->activated_at && $user->first_login && $user->temporary_password, 409, 'Only pending invited users can be reinvited.');
            $this->users->invitationRole($actor, $user->role_id);
            abort_if($user->invitation_last_sent_at && $user->invitation_last_sent_at->gt(now()->subMinute()), 429, 'Wait 60 seconds before resending.');
            $password = Str::random(32).'#9Aa';
            $user->forceFill([
                'password_hash' => Hash::make($password), 'temporary_password_expires_at' => now()->addDay(),
                'force_password_reset_at' => now(),
                'invitation_generation' => (int) $user->invitation_generation + 1, 'auth_generation' => (int) $user->auth_generation + 1,
            ])->save();
            AuthenticationChallenge::query()->where('user_id', $id)->where('school_id', $schoolId)
                ->whereIn('purpose', ['first_login', 'activation'])->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            $this->stampInvitation($user);
            $this->audit->record($actor, 'school_user_invitation_reissued', 'users', $id, [], ['invitation_generation' => $user->invitation_generation]);
            $this->schedule($user, $password);

            return ['id' => $user->id, 'status' => 'pending_activation'];
        });
    }

    private function stampInvitation(User $user): void
    {
        // Bind an ISO timestamp directly: Eloquent's date cast removes its UTC offset.
        DB::table('users')->where('id', $user->id)->where('school_id', $user->school_id)
            ->update(['invitation_last_sent_at' => now()->toIso8601String()]);
        $user->refresh();
    }

    private function schedule(User $user, string $password): void
    {
        $school = School::findOrFail($user->school_id);
        $id = $user->id;
        $schoolId = $school->id;
        $generation = $user->invitation_generation;
        $email = $user->email;
        $mail = new SchoolUserInvitationMail($school->school_name, $school->school_code, $email, $password,
            rtrim(config('communication.frontend_url', 'http://localhost:3000'), '/').'/login', $user->temporary_password_expires_at->toIso8601String());
        DB::afterCommit(function () use ($id, $schoolId, $generation, $email, $mail) {
            if (! User::query()->whereKey($id)->where('school_id', $schoolId)->where('invitation_generation', $generation)
                ->where('active', true)->where('is_deleted', false)->where('temporary_password', true)->whereNull('activated_at')->exists()) {
                return;
            }
            try {
                Mail::to($email)->send($mail);
            } catch (\Throwable) {
                // No credentials, recipient addresses or provider exception text in logs.
                Log::warning('School user invitation delivery failed.', ['school_id' => $schoolId, 'user_id' => $id]);
            }
        });
    }
}
