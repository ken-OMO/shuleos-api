<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorDiagnosticsTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorDiagnosticsService.php'));
        $this->assertStringContainsString('diagnostic_checks', $source);
        $this->assertStringContainsString("'database'", $source);
        $this->assertStringContainsString('unavailable', $source);
    }
}
