<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        try {

            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            if (! $user->role_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A role is required.',
                ], 403);
            }

            $userRole = \DB::table('roles')
                ->where('id', $user->role_id)
                ->value('role_name');

            if ($userRole !== $role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied.',
                ], 403);
            }

            return $next($request);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify role.',
            ], 401);
        }
    }
}
