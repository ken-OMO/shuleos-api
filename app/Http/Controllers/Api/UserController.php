<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * List Users
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => User::where('is_deleted', false)
                ->orderBy('first_name')
                ->get()
        ]);
    }

    /**
     * Get Single User
     */
    public function show($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    /**
     * Create User
     */
    public function store(Request $request)
    {
        $request->validate([
            'school_id' => 'required',
            'role_id' => 'required',
            'username' => 'required|unique:users,username',
            'first_name' => 'required',
            'last_name' => 'required'
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $request->school_id,
            'role_id' => $request->role_id,
            'username' => $request->username,
            'password_hash' => Hash::make('ChangeMe123!'),
            'email' => $request->email,
            'phone' => $request->phone,
            'first_name' => $request->first_name,
            'middle_name' => $request->middle_name,
            'last_name' => $request->last_name,
            'active' => true,
            'first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Update User
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->update($request->except([
            'id',
            'password_hash'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Soft Delete User
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->update([
            'is_deleted' => true,
            'deleted_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Reset Password
     */
    public function resetPassword($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->update([
            'password_hash' => Hash::make('ChangeMe123!'),
            'first_login' => true,
            'updated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    }

    /**
     * Assign Additional Role
     */
    public function assignRole(Request $request, $id)
    {
        $request->validate([
            'role_id' => 'required'
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $id,
            'role_id' => $request->role_id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully'
        ]);
    }
}
