<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthContextService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ModulePermissionMiddleware
{
    public function __construct(private AuthContextService $authContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $prefix = $request->segment(2);

        $required = match (true) {
            $prefix === 'learners'
                && $request->segment(4) === 'guardians' => config('module_permissions.guardians'),

            default => config("module_permissions.{$prefix}"),
        };

        if (! $required) {
            return response()->json(['success' => false, 'message' => 'No permission policy is configured for this module.'], 403);
        }

        try {
            if (! $this->authContext->hasPermission($user, $required)) {
                return response()->json(['success' => false, 'message' => 'Permission denied.'], 403);
            }
        } catch (AuthenticationException) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        } catch (AuthorizationException) {
            return response()->json(['success' => false, 'message' => 'Access is unavailable.'], 403);
        }

        return $next($request);
    }
}
