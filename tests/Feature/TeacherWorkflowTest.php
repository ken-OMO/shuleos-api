<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherWorkflowTest extends TestCase
{
    public function test_workflow_service_has_allowlist_transition_and_separation_controls(): void
    {
        $source = file_get_contents(app_path('Services/TeacherPortal/TeacherWorkflowService.php'));
        $this->assertStringContainsString('private const TYPES', $source);
        $this->assertStringContainsString('Submitters cannot approve their own work', $source);
        $this->assertStringContainsString('Invalid workflow transition', $source);
        $this->assertStringContainsString('approved_snapshot', $source);
        $this->assertStringNotContainsString('request()->input(\'entity_type\')', $source);
    }
}
