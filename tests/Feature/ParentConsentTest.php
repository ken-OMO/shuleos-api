<?php

namespace Tests\Feature;

use Tests\TestCase;

class ParentConsentTest extends TestCase
{
    public function test_consent_response_records_published_version_and_rejects_unpublished_states(): void
    {
        $source = file_get_contents(app_path('Services/ParentPortal/ParentPhaseTwoWorkflowService.php'));
        $this->assertStringContainsString("where('status', 'published')", $source);
        $this->assertStringContainsString("'consent_version' => \$consent->consent_version", $source);
        $this->assertStringContainsString("whereNull('withdrawn_at')", $source);
    }
}
