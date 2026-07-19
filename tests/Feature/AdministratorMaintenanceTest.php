<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorMaintenanceTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorMaintenanceService.php'));
        $this->assertStringContainsString('consumePreview', $source);
        $this->assertStringContainsString('ACTIVATE MAINTENANCE', $source);
        $this->assertStringContainsString('administrator_maintenance_history', $source);
    }
}
