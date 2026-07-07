<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Users';

    /**
     * Relationships loaded with user responses.
     */
    private const RELATIONS = [

        'school',

        'role',

    ];

    /**
     * Display a listing of users.
     */
    public function index()
    {
        $users = User::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderBy('first_name')
        ->get();

        return $this->success(

            UserResource::collection(

                $users

            ),

            'Users retrieved successfully.'

        );
    }

    /**
     * Display the specified user.
     */
    public function show(string $id)
    {
        $user = User::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($user)) {

            return $this->notFound(

                'User not found.'

            );

        }

        return $this->success(

            new UserResource(

                $user

            ),

            'User retrieved successfully.'

        );
    }
        /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'role_id' => 'required|exists:roles,id',

            'username' => 'required|string|max:100|unique:users,username',

            'first_name' => 'required|string|max:100',

            'middle_name' => 'nullable|string|max:100',

            'last_name' => 'required|string|max:100',

            'email' => 'nullable|email|max:255',

            'phone' => 'nullable|string|max:20',

        ]);

        $this->beginTransaction();

        try {

            $user = User::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'role_id' => $validated['role_id'],

                'username' => $validated['username'],

                'password_hash' => Hash::make('ChangeMe123!'),

                'email' => $validated['email'] ?? null,

                'phone' => $validated['phone'] ?? null,

                'first_name' => $validated['first_name'],

                'middle_name' => $validated['middle_name'] ?? null,

                'last_name' => $validated['last_name'],

                'active' => true,

                'first_login' => true,

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $user,

                oldValues: null,

                newValues: $user->toArray(),

                description: 'Created user account.'

            );

            $this->commit();

            $this->loadRelations(

                $user,

                self::RELATIONS

            );

            return $this->created(

                new UserResource(

                    $user

                ),

                'User created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create user.',

                [

                    'school_id' => $request->school_id,

                    'username' => $request->username,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create user.'

            );

        }
    }
        /**
     * Update the specified user.
     */
    public function update(Request $request, string $id)
    {
        $user = User::find($id);

        if ($this->modelNotFound($user)) {

            return $this->notFound(

                'User not found.'

            );

        }

        if ($this->isDeleted($user)) {

            return $this->badRequest(

                'User has been deleted.'

            );

        }

        $validated = $request->validate([

            'role_id' => 'sometimes|exists:roles,id',

            'username' => 'sometimes|string|max:100|unique:users,username,' . $id . ',id',

            'first_name' => 'sometimes|string|max:100',

            'middle_name' => 'nullable|string|max:100',

            'last_name' => 'sometimes|string|max:100',

            'email' => 'nullable|email|max:255',

            'phone' => 'nullable|string|max:20',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $user->toArray();

            $user->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $user,

                oldValues: $oldValues,

                newValues: $user->fresh()->toArray(),

                description: 'Updated user account.'

            );

            $this->commit();

            $this->loadRelations(

                $user,

                self::RELATIONS

            );

            return $this->success(

                new UserResource(

                    $user

                ),

                'User updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update user.',

                [

                    'user_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update user.'

            );

        }
    }
        /**
     * Soft delete the specified user.
     */
    public function destroy(Request $request, string $id)
    {
        $user = User::find($id);

        if ($this->modelNotFound($user)) {

            return $this->notFound(

                'User not found.'

            );

        }

        if ($this->isDeleted($user)) {

            return $this->badRequest(

                'User has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $user->toArray();

            $user->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $user,

                oldValues: $oldValues,

                newValues: $user->fresh()->toArray(),

                description: 'Soft deleted user account.'

            );

            $this->commit();

            return $this->success(

                null,

                'User deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete user.',

                [

                    'user_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete user.'

            );

        }
    }
        /**
     * Reset user password.
     */
    public function resetPassword(Request $request, string $id)
    {
        $user = User::find($id);

        if ($this->modelNotFound($user)) {

            return $this->notFound(

                'User not found.'

            );

        }

        if ($this->isDeleted($user)) {

            return $this->badRequest(

                'User has been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $user->toArray();

            $user->update([

                'password_hash' => Hash::make('ChangeMe123!'),

                'first_login' => true,

                'failed_login_attempts' => 0,

                'account_locked_until' => null,

                'password_reset_token' => null,

                'password_reset_expires' => null,

                'password_changed_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Password Reset',

                model: $user,

                oldValues: $oldValues,

                newValues: $user->fresh()->toArray(),

                description: 'Administrator reset user password.'

            );

            $this->commit();

            return $this->success(

                null,

                'Password reset successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to reset password.',

                [

                    'user_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to reset password.'

            );

        }
    }
        /**
     * Assign an additional role to the user.
     */
    public function assignRole(Request $request, string $id)
    {
        $user = User::find($id);

        if ($this->modelNotFound($user)) {

            return $this->notFound(

                'User not found.'

            );

        }

        if ($this->isDeleted($user)) {

            return $this->badRequest(

                'User has been deleted.'

            );

        }

        $validated = $request->validate([

            'role_id' => 'required|exists:roles,id',

        ]);

        $this->beginTransaction();

        try {

            DB::table('user_roles')->updateOrInsert(

                [

                    'user_id' => $user->id,

                    'role_id' => $validated['role_id'],

                ],

                []

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Assign Role',

                model: $user,

                oldValues: null,

                newValues: [

                    'role_id' => $validated['role_id'],

                ],

                description: 'Assigned additional role to user.'

            );

            $this->commit();

            return $this->success(

                null,

                'Role assigned successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to assign role.',

                [

                    'user_id' => $id,

                    'role_id' => $validated['role_id'],

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to assign role.'

            );

        }
    }
}
