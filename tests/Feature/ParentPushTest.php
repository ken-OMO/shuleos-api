<?php

namespace Tests\Feature;

use Tests\TestCase;

class ParentPushTest extends TestCase
{
    public function test_parent_push_is_disabled_by_default_queued_and_token_safe(): void
    {
        $config = require config_path('parent_portal_phase_two.php');
        $service = file_get_contents(app_path('Services/ParentPortal/ParentPushService.php'));
        $job = file_get_contents(app_path('Jobs/DeliverParentPush.php'));
        $resource = file_get_contents(app_path('Http/Resources/ParentPhaseTwoResource.php'));
        $this->assertFalse($config['push_enabled']);
        $this->assertStringContainsString('DeliverParentPush::dispatch', $service);
        $this->assertStringContainsString('Crypt::decryptString', $job);
        $this->assertStringContainsString("'provider_message_id'", $resource);
    }
}
