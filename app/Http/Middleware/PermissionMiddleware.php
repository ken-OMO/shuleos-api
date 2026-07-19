<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function __construct(private AuthContextService $authContext) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (! $user) {
                throw new AuthenticationException('Unauthenticated.');
            }

            if (! $this->authContext->hasPermission($user, $permission)) {
                return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
            }

            return $next($request);
        } catch (AuthorizationException) {
            return response()->json(['success' => false, 'message' => 'Access is unavailable.'], 403);
        } catch (AuthenticationException|JWTException) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
    }
}
