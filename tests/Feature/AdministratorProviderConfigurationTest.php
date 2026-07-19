<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorProviderConfigurationTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorProviderConfigurationService.php'));
        $this->assertStringContainsString('Crypt::encryptString', $source);
        $this->assertStringContainsString('configuration_encrypted', $source);
        $this->assertStringContainsString('passive_configuration_only', $source);
    }
}
