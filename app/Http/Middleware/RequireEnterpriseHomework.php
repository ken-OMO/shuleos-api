<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Homework\EnterpriseHomeworkAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireEnterpriseHomework
{
    public function handle(Request $request, Closure $next): Response
    {
        app(EnterpriseHomeworkAccess::class)->authorize($request->user()?->school_id);

        return $next($request);
    }
}
