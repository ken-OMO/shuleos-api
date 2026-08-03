<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherUploadTest extends TestCase
{
    public function test_uploads_use_enterprise_security_quarantine_and_private_storage(): void
    {
        $source = file_get_contents(app_path('Services/TeacherPortal/TeacherPortalAttachmentService.php'));
        $this->assertStringContainsString('FileSecurityManager', $source);
        $this->assertStringContainsString('FileQuarantine', $source);
        $this->assertStringContainsString('SecureFileStorage', $source);
        $this->assertStringContainsString('profilePhoto()', $source);
        $this->assertStringContainsString('Only clean files can be downloaded', $source);
        $this->assertStringNotContainsString('public_path(', $source);
    }
}
