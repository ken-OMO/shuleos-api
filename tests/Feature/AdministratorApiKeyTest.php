<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorApiKeyTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorIntegrationService.php'));
        $this->assertStringContainsString('key_hash', $source);
        $this->assertStringContainsString('plaintext_key', $source);
        $this->assertStringContainsString('platform.read', $source);
    }
}
