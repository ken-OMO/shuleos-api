<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorPlatformSettingTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorDiagnosticsService.php'));
        $this->assertStringContainsString('platform_settings', $source);
        $this->assertStringContainsString('not allowlisted', $source);
        $this->assertStringContainsString('administrator_platform_setting_history', $source);
    }
}
