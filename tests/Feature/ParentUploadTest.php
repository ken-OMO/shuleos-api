<?php

namespace Tests\Feature;

use App\Http\Resources\ParentAttachmentResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class ParentUploadTest extends TestCase
{
    public function test_upload_pipeline_uses_secure_storage_and_hides_storage_identifiers(): void
    {
        $source = file_get_contents(app_path('Services/ParentPortal/ParentPortalAttachmentService.php'));
        $this->assertStringContainsString('FileSecurityManager', $source);
        $this->assertStringContainsString('SecureFileStorage', $source);
        $data = (new ParentAttachmentResource(['id' => 'safe', 'storage_id' => 'secret', 'source_hash' => 'secret', 'status' => 'pending_scan']))->toArray(Request::create('/'));
        $this->assertSame(['id' => 'safe', 'status' => 'pending_scan'], $data);
    }
}
