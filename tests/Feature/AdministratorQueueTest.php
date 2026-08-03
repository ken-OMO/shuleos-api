<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorQueueTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorQueueService.php'));
        $this->assertStringContainsString('select(\'uuid\', \'connection\', \'queue\', \'failed_at\')', $source);
        $this->assertStringContainsString('pushRaw', $source);
        $this->assertStringContainsString('FORGET FAILED JOB', $source);
    }
}
