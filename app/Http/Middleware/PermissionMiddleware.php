<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        try {

            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $hasPermission = DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $user->role_id)
                ->where('permissions.permission_name', $permission)
                ->exists();

            if (! $hasPermission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied: '.$permission,
                ], 403);
            }

            return $next($request);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify permission.',
            ], 401);
        }
    }
}
