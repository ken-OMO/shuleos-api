<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorSystemHealthTest extends TestCase
{
    public function test_health_service_does_not_read_environment_or_execute_commands(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/AdministratorOperationsService.php'));
        foreach (['env(', 'Artisan::call', 'DB_PASSWORD', 'queue payload'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
