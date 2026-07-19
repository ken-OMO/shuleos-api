<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Login user and generate JWT token
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $user = User::where('username', $request->username)
            ->first();

        if (! $user || ! $user->active || $user->is_deleted || ($user->account_locked_until && $user->account_locked_until->isFuture())) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password',
            ], 401);
        }

        if (! Hash::check($request->password, $user->password_hash)) {
            $attempts = (int) $user->failed_login_attempts + 1;
            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'last_failed_login' => now(),
                'account_locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null,
            ])->save();

            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password',
            ], 401);
        }

        $school = $user->school;
        if (! $school || ! $school->active || in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true)) {
            return response()->json(['success' => false, 'message' => 'School access is unavailable'], 403);
        }

        $user->forceFill(['failed_login_attempts' => 0, 'account_locked_until' => null, 'last_login' => now()])->save();

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'school_id' => $user->school_id,
                'role_id' => $user->role_id,
                'password_reset_required' => (bool) ($user->first_login || $user->force_password_reset_at),
            ],
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed.',
            ], 401);
        }
    }

    /**
     * Logout user
     */
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed.',
            ], 401);
        }
    }

    /**
     * Refresh JWT token
     */
    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'token' => $newToken,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed.',
            ], 401);
        }
    }
}
