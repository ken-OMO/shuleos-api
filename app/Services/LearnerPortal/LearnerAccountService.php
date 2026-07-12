<?php

namespace App\Services\LearnerPortal;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerAccountService
{
    public function create(string $school, string $id, array $d): array
    {
        return DB::transaction(function () use ($school, $id, $d) {
            $l = Learner::whereKey($id)->where('school_id', $school)->where('active', true)->where('is_deleted', false)->lockForUpdate()->first();
            if (! $l) {
                throw ValidationException::withMessages(['learner' => 'Active learner not found in this school.']);
            }if ($l->user_id) {
                throw ValidationException::withMessages(['learner' => 'Learner already has a portal account.']);
            }$role = DB::table('roles')->whereRaw('LOWER(role_name)=?', ['learner'])->value('id');
            if (! $role) {
                throw ValidationException::withMessages(['role' => 'Learner role is unavailable.']);
            }$username = Str::lower(Str::slug($d['username'] ?? $l->admission_no, '_'));
            if (! $username) {
                $username = 'learner_'.Str::lower(Str::random(8));
            }if (User::where('username', $username)->exists()) {
                throw ValidationException::withMessages(['username' => 'Username is already in use.']);
            }$generated = ! isset($d['password']);
            $password = $d['password'] ?? Str::password(12);
            $u = User::create(['id' => (string) Str::uuid(), 'school_id' => $school, 'role_id' => $role, 'username' => $username, 'password_hash' => Hash::make($password), 'email' => $l->email ?? null, 'phone' => null, 'first_name' => $l->first_name, 'middle_name' => $l->middle_name, 'last_name' => $l->last_name, 'active' => true, 'first_login' => true]);
            $l->update(['user_id' => $u->id, 'portal_enabled' => true, 'portal_activated_at' => now()]);

            return ['user' => ['id' => $u->id, 'username' => $u->username, 'first_login' => true], 'temporary_password' => $generated ? $password : null];
        });
    }

    public function status(string $school, string $id, bool $enabled): Learner
    {
        $l = Learner::whereKey($id)->where('school_id', $school)->whereNotNull('user_id')->firstOrFail();
        DB::transaction(function () use ($l, $enabled) {
            $l->update(['portal_enabled' => $enabled, 'portal_activated_at' => $enabled ? ($l->portal_activated_at ?? now()) : $l->portal_activated_at]);
            $l->user()->update(['active' => $enabled]);
        });

        return $l->refresh();
    }

    public function reset(string $school, string $id, ?string $password = null): array
    {
        $l = Learner::whereKey($id)->where('school_id', $school)->whereNotNull('user_id')->firstOrFail();
        $generated = $password === null;
        $password ??= Str::password(12);
        $l->user()->update(['password_hash' => Hash::make($password), 'first_login' => true]);

        return ['user_id' => $l->user_id, 'temporary_password' => $generated ? $password : null];
    }
}
