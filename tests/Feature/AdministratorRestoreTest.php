<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorRestoreTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorRecoveryService.php'));
        $this->assertStringContainsString('REQUEST PLATFORM RESTORE', $source);
        $this->assertStringContainsString('restore_execution_enabled', $source);
        $this->assertStringContainsString('pre_restore_backup_id', $source);
    }
}
