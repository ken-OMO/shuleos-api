<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorFeatureFlagTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorFeatureFlagService.php'));
        $this->assertStringContainsString('Feature flag key is not allowlisted.', $source);
        $this->assertStringContainsString('entitlement_override', $source);
        $this->assertStringContainsString('administrator_feature_flag_history', $source);
    }
}
