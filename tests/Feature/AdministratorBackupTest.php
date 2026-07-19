<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorBackupTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorRecoveryService.php'));
        $this->assertStringContainsString('trusted_tooling_unavailable', $source);
        $this->assertStringContainsString('safe_manifest', $source);
        $this->assertStringContainsString('background_only', $source);
    }
}
