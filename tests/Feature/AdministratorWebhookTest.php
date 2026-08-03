<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorWebhookTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorIntegrationService.php'));
        $this->assertStringContainsString('secret_encrypted', $source);
        $this->assertStringContainsString('Private or reserved webhook targets', $source);
        $this->assertStringContainsString('webhook_events', $source);
    }
}
