<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdministratorUsersTest extends TestCase
{
    public function test_legacy_user_mutations_are_disabled_and_admin_routes_are_permissioned(): void
    {
        $routes = collect(Route::getRoutes());
        $this->assertNull($routes->first(fn ($route) => $route->uri() === 'api/users' && in_array('POST', $route->methods(), true)));
        $this->assertNull($routes->first(fn ($route) => $route->uri() === 'api/users/{id}/assign-role'));
        $route = $routes->first(fn ($route) => $route->uri() === 'api/admin/users');
        $this->assertContains('permission:view_school_users', $route->gatherMiddleware());
    }
}
