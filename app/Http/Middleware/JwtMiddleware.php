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

class JwtMiddleware
{
    public function __construct(private AuthContextService $authContext) {}

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
            if ($generation !== (int) ($user->auth_generation ?: 1)) {
                throw new AuthenticationException('Unauthenticated.');
            }

            $this->authContext->assertAccessible($user);
            $request->setUserResolver(fn () => $user);

        } catch (AuthorizationException) {
            return response()->json(['success' => false, 'message' => 'Access is unavailable.'], 403);

        } catch (AuthenticationException) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);

        } catch (JWTException) {

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);

        }

        return $next($request);
    }
}
