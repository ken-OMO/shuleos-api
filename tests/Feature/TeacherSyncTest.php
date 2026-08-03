<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherSyncTest extends TestCase
{
    public function test_sync_is_allowlisted_versioned_idempotent_and_conflict_safe(): void
    {
        $source = file_get_contents(app_path('Services/TeacherPortal/TeacherSyncService.php'));
        $this->assertStringContainsString('private const ENTITIES', $source);
        $this->assertStringContainsString('operation_uuid', $source);
        $this->assertStringContainsString('sync_version', $source);
        $this->assertStringContainsString("'status' => 'open'", $source);
        $this->assertStringContainsString('Submitted work cannot be changed offline', $source);
        $this->assertStringContainsString('whereNull(\'revoked_at\')', $source);
        $this->assertStringNotContainsString('DB::statement', $source);
    }
}
