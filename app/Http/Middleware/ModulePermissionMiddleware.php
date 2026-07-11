<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ModulePermissionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user || ! $user->role_id) {
            return response()->json(['success' => false, 'message' => 'Authenticated role required.'], 403);
        }

        $prefix = $request->segment(2);
        $required = config("module_permissions.{$prefix}");

        if (! $required) {
            return response()->json(['success' => false, 'message' => 'No permission policy is configured for this module.'], 403);
        }

        $roleName = DB::table('roles')->where('id', $user->role_id)->value('role_name');

        if ($roleName === 'Platform Owner') {
            return $next($request);
        }

        $permissions = Cache::remember(
            "role_permissions:{$user->role_id}",
            now()->addMinutes(10),
            fn () => DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $user->role_id)
                ->pluck('permissions.permission_name')
                ->all()
        );

        if (! in_array($required, $permissions, true)) {
            return response()->json(['success' => false, 'message' => 'Permission denied.'], 403);
        }

        return $next($request);
    }
}
