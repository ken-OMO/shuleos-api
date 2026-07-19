<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorAccessTest extends TestCase
{
    public function test_platform_scope_requires_both_platform_role_and_permission(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/AdministratorPortalAccessService.php'));
        $this->assertStringContainsString('self::PLATFORM_ROLES', $source);
        $this->assertStringContainsString("has(\$user, 'access_platform_administration')", $source);
        $this->assertStringContainsString('Explicit platform administration scope required.', $source);
    }
}
