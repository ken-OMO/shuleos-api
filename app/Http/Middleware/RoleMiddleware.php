<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(private AuthContextService $authContext) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        try {

            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                throw new AuthenticationException('Unauthenticated.');
            }

            if (! $this->authContext->hasRole($user, $role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden.',
                ], 403);
            }

            return $next($request);

        } catch (AuthorizationException) {

            return response()->json([
                'success' => false,
                'message' => 'Access is unavailable.',
            ], 403);

        } catch (\Throwable) {

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }
    }
}
