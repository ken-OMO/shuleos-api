<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        try {

            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->role_id) {

                $userRole = \DB::table('roles')
                    ->where('id', $user->role_id)
                    ->value('role_name');

                if ($userRole !== $role) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Access denied. Required role: ' . $role
                    ], 403);
                }
            }

            return $next($request);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 401);
        }
    }
}
