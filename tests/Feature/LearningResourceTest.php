<?php

namespace Tests\Feature;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Http\Resources\LearningResourceVersionResource;
use App\Models\LearningResource;
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

    public function test_video_links_are_restricted_to_explicit_providers(): void
    {
        $service = app(LearningResourceService::class);
        $this->assertSame('https://www.youtube.com/watch?v=abc', $service->safeUrl('https://WWW.YouTube.com/watch?v=abc', 'video'));
        $this->assertSame('https://player.vimeo.com/video/123', $service->safeUrl('https://player.vimeo.com/video/123', 'video'));

        foreach (['https://example.com/video', 'https://user:secret@youtube.com/watch?v=x', 'https://127.0.0.1/video', 'https://youtube.com.evil.test/video'] as $url) {
            try {
                $service->safeUrl($url, 'video');
                $this->fail('Unapproved video provider accepted.');
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

    public function test_models_hide_storage_security_metadata_during_serialization(): void
    {
        $version = new LearningResourceVersion(['id' => 'v1', 'storage_id' => 'storage-secret', 'source_hash' => 'source-secret', 'stored_hash' => 'stored-secret']);
        $resource = new LearningResource(['id' => 'r1', 'title' => 'Safe']);
        $resource->setRelation('currentVersion', $version);
        $json = json_encode($resource->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('storage-secret', $json);
        $this->assertStringNotContainsString('source-secret', $json);
        $this->assertStringNotContainsString('stored-secret', $json);
    }
}
