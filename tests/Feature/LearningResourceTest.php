<?php

namespace Tests\Feature;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Http\Resources\LearningResourceVersionResource;
use App\Models\LearningResourceVersion;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LearningResourceTest extends TestCase
{
    public function test_learning_resource_policy_excludes_executables_archives_html_svg_and_macros(): void
    {
        $p = FilePolicyFactory::learningResource();
        foreach (['exe', 'zip', 'html', 'svg', 'docm', 'xlsm', 'pptm', 'js', 'php'] as $x) {
            $this->assertNotContains($x, $p->allowedExtensions);
        }$this->assertTrue($p->requireVirusScan);
        $this->assertTrue($p->requireMagicNumberValidation);
        $this->assertTrue($p->requireQuarantine);
        $this->assertTrue($p->encryptAfterUpload);
        $this->assertFalse($p->allowPasswordProtectedFiles);
        $this->assertFalse($p->allowMacros);
    }

    public function test_external_links_accept_https_and_reject_unsafe_inputs(): void
    {
        $s = app(LearningResourceService::class);
        $this->assertSame('https://www.youtube.com/watch?v=test', $s->safeUrl('https://www.youtube.com/watch?v=test'));
        foreach (['http://example.com/a', 'javascript:alert(1)', 'file:///etc/passwd', 'https://localhost/a', '<iframe src="https://example.com"></iframe>'] as $url) {
            try {
                $s->safeUrl($url);
                $this->fail('Unsafe URL accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_public_version_resource_never_exposes_storage_identifiers_or_hashes(): void
    {
        $v = new LearningResourceVersion(['id' => 'v1', 'version_number' => 1, 'storage_id' => 'secret', 'source_hash' => 'source', 'stored_hash' => 'stored', 'mime_type' => 'application/pdf', 'encrypted' => true]);
        $data = (new LearningResourceVersionResource($v))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('storage_id', $data);
        $this->assertArrayNotHasKey('source_hash', $data);
        $this->assertArrayNotHasKey('stored_hash', $data);
    }
}
