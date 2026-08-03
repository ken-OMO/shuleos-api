<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (

            ! $user

            || ! $user->school_id

        ) {

            return response()->json([

                'success' => false,

                'message' => 'School context not found.',

            ], 403);
        }

        $request->attributes->set(

            'tenant_school_id',

            $user->school_id

        );

        $request->merge([
            'school_id' => $user->school_id,
        ]);

        return $next($request);
    }
}
