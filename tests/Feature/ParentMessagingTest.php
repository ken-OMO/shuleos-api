<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ParentMessagingTest extends TestCase
{
    public function test_message_destinations_are_server_resolved_and_routes_are_throttled(): void
    {
        $service = file_get_contents(app_path('Services/ParentPortal/ParentPhaseTwoWorkflowService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/ParentPortalPhaseTwoController.php'));
        $this->assertStringContainsString('resolveStaff', $service);
        $this->assertStringNotContainsString("'staff_user_id' =>", $controller);
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/parent/conversations/{conversation}/messages' && in_array('POST', $route->methods(), true));
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }
}
