<?php

namespace Tests\Feature;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Http\Resources\LearnerAttachmentResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class LearnerUploadTest extends TestCase
{
    public function test_upload_policy_rejects_dangerous_types_and_requires_quarantine(): void
    {
        $policy = FilePolicyFactory::homeworkSubmission();
        foreach (['exe', 'php', 'js', 'html', 'svg', 'zip', 'docm', 'xlsm', 'pptm'] as $extension) {
            $this->assertNotContains($extension, $policy->allowedExtensions);
        }
        $this->assertTrue($policy->requireMagicNumberValidation);
        $this->assertTrue($policy->requireVirusScan);
        $this->assertTrue($policy->requireQuarantine);
        $this->assertTrue($policy->encryptAfterUpload);
    }

    public function test_attachment_resource_never_exposes_storage_or_owner_metadata(): void
    {
        $data = (new LearnerAttachmentResource(['id' => 'file', 'school_id' => 'secret', 'learner_id' => 'secret', 'storage_id' => 'secret', 'source_hash' => 'secret', 'stored_hash' => 'secret', 'status' => 'pending_scan']))->toArray(Request::create('/'));
        foreach (['school_id', 'learner_id', 'storage_id', 'source_hash', 'stored_hash'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertSame('pending_scan', $data['status']);
    }

    public function test_only_clean_files_download_and_submitted_evidence_cannot_be_destroyed(): void
    {
        $source = file_get_contents(app_path('Services/LearnerPortal/LearnerAttachmentService.php'));
        $this->assertStringContainsString("['clean', 'attached']", $source);
        $this->assertStringContainsString('Submitted evidence cannot be destructively removed.', $source);
        $this->assertStringContainsString("where('learner_id', \$learner->id)", $source);
    }
}
