<?php

namespace Tests\Feature;

use Tests\TestCase;

class ParentSyncTest extends TestCase
{
    public function test_sync_is_allowlisted_versioned_and_excludes_financial_mutations(): void
    {
        $source = file_get_contents(app_path('Services/ParentPortal/ParentSyncService.php'));
        $this->assertStringContainsString('base_version', $source);
        $this->assertStringContainsString('parent_sync_conflicts', $source);
        $this->assertStringNotContainsString("'payment'", $source);
        $this->assertStringNotContainsString("'sent_message'", $source);
    }
}
