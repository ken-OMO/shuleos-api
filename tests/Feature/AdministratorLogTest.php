<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorLogTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorLogService.php'));
        $this->assertStringContainsString('log_files', $source);
        $this->assertStringContainsString('262144', $source);
        $this->assertStringContainsString('[redacted-email]', $source);
    }
}
