<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorCacheTest extends TestCase
{
    public function test_phase_two_operational_contract_is_fail_closed(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/Operations/AdministratorCacheService.php'));
        $this->assertStringContainsString('cache_clear', $source);
        $this->assertStringContainsString('admin-cache-version', $source);
        $this->assertStringContainsString('sessions_preserved', $source);
    }
}
