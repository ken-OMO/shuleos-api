<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorSchedulerTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorSchedulerService.php'));
        $this->assertStringContainsString('scheduler_tasks', $source);
        $this->assertStringContainsString('active run', $source);
        $this->assertStringContainsString('RunAdministratorScheduledTask', $source);
    }
}
