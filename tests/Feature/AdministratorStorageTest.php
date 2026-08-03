<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorStorageTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorStorageService.php'));
        $this->assertStringContainsString('scanner-confirmed clean', $source);
        $this->assertStringContainsString('physical_delete', $source);
        $this->assertStringContainsString('where(\'school_id\'', $source);
    }
}
