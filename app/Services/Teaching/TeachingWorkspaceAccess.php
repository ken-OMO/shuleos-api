<?php

namespace App\Services\Teaching;

use App\Services\Auth\AuthContextService;
use Illuminate\Http\Request;

final class TeachingWorkspaceAccess
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function school(Request $request): string
    {
        $auth = $this->auth;
        $actor = $request->user();
        $context = $auth->resolve($actor);
        $roles = $context['roles'];

        abort_unless($context['scope'] === 'school' && $context['school_id'] === $request->attributes->get('tenant_school_id')
            && ! $request->attributes->get('client_supplied_school_id')
            && ((in_array('Headteacher', $roles, true) && $auth->hasPermission($actor, 'manage_academics'))
                || (in_array('School Admin', $roles, true) && $auth->hasPermission($actor, 'access_administrator_portal'))), 403);

        return (string) $context['school_id'];
    }
}
