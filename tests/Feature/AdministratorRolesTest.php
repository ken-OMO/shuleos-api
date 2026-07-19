<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorRolesTest extends TestCase
{
    public function test_platform_permissions_are_explicitly_blocked_for_school_roles(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/AdministratorRolePermissionService.php'));
        $this->assertStringContainsString('School roles cannot receive platform permissions.', $source);
        $this->assertStringContainsString('System role permissions cannot be changed here.', $source);
    }
}
