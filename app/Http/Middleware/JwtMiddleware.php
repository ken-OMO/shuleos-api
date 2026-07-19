<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {

            if (! $user = JWTAuth::parseToken()->authenticate()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $generation = (int) (JWTAuth::parseToken()->getPayload()->get('auth_generation') ?? 1);
            if (! $user->active || $user->is_deleted || $generation !== (int) ($user->auth_generation ?: 1)) {
                return response()->json(['success' => false, 'message' => 'Session has been revoked.'], 401);
            }

            $school = $user->school;
            if (! $school || ! $school->active || in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true)) {
                return response()->json(['success' => false, 'message' => 'School access is unavailable.'], 403);
            }

        } catch (JWTException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Token is invalid or missing',
            ], 401);

        }

        return $next($request);
    }
}
