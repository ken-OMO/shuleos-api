<?php

namespace Tests\Feature;

use Tests\TestCase;

class ParentAppointmentTest extends TestCase
{
    public function test_appointment_targets_and_transitions_are_server_controlled(): void
    {
        $service = file_get_contents(app_path('Services/ParentPortal/ParentPhaseTwoWorkflowService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/ParentPortalPhaseTwoController.php'));
        $this->assertStringContainsString('resolveStaff', $service);
        $this->assertStringContainsString("'accept-proposal'", $service);
        $this->assertStringNotContainsString("'resolved_staff_user_id' => ['required'", $controller);
    }
}
