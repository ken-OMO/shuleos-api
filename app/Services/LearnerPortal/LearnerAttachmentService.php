<?php

namespace App\Services\LearnerPortal;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\LearnerPortalAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class LearnerAttachmentService
{
    private const CONTEXTS = ['homework_submission', 'profile_image', 'help_request'];

    public function __construct(private FileSecurityManager $security, private FileQuarantine $quarantine, private SecureFileStorage $storage, private LearnerPortalAccessService $access, private LearnerPortalAuditService $audit) {}

    public function upload(User $user, string $context, ?string $contextId, UploadedFile $file): LearnerPortalAttachment
    {
        abort_unless(in_array($context, self::CONTEXTS, true), 422);
        $learner = $this->access->learner($user);
        $this->assertContext($user, $context, $contextId);
        $policy = $context === 'profile_image' ? FilePolicyFactory::profilePhoto() : FilePolicyFactory::homeworkSubmission();
        if ($this->security->scan($file, $policy)->failed()) {
            throw ValidationException::withMessages(['file' => 'The file failed the secure learner upload policy.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $duplicate = LearnerPortalAttachment::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('source_hash', $hash)->whereNotIn('status', ['rejected', 'archived'])->first();
        if ($duplicate) {
            return $duplicate;
        }
        $quarantineId = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $quarantineId);
        try {
            $attachment = LearnerPortalAttachment::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'learner_id' => $learner->id, 'context_type' => $context, 'context_id' => $contextId, 'original_filename' => $file->getClientOriginalName(), 'safe_filename' => Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension()), 'mime_type' => $file->getMimeType(), 'extension' => strtolower($file->getClientOriginalExtension()), 'source_size' => $file->getSize(), 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'], 'storage_id' => $stored['storage_id'], 'status' => config('learner_portal_phase_two.upload_scan_trusted') ? 'clean' : 'pending_scan']);
            $this->audit->record($user, 'attachment_uploaded', 'learner_portal_attachment', $attachment->id, ['context' => $context]);

            return $attachment;
        } catch (Throwable $exception) {
            $this->storage->delete($stored['storage_id']);
            throw $exception;
        }
    }

    public function find(User $user, string $id): LearnerPortalAttachment
    {
        $learner = $this->access->learner($user);

        return LearnerPortalAttachment::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereNull('archived_at')->firstOrFail();
    }

    public function download(User $user, string $id): BinaryFileResponse
    {
        $file = $this->find($user, $id);
        abort_unless(in_array($file->status, ['clean', 'attached'], true), 409, 'Only clean files can be downloaded.');
        abort_unless(hash_equals($file->stored_hash, $this->storage->hash($file->storage_id)), 409, 'Stored file integrity check failed.');
        $directory = storage_path('app/private/learner-delivery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($file->storage_id, $path);
        $this->audit->record($user, 'attachment_downloaded', 'learner_portal_attachment', $id);

        return response()->download($path, $file->safe_filename, ['Content-Type' => $file->mime_type])->deleteFileAfterSend(true);
    }

    public function archive(User $user, string $id): void
    {
        $file = $this->find($user, $id);
        abort_if($file->status === 'attached' || $file->attached_at, 409, 'Submitted evidence cannot be destructively removed.');
        $file->update(['status' => 'archived', 'archived_at' => now()]);
        $this->audit->record($user, 'attachment_archived', 'learner_portal_attachment', $id);
    }

    private function assertContext(User $user, string $context, ?string $id): void
    {
        if ($context === 'profile_image') {
            abort_if($id, 422);

            return;
        }
        abort_unless($id, 422);
        if ($context === 'homework_submission') {
            $learner = $this->access->learner($user);
            $submission = DB::table('homework_submissions')->whereKey($id)->where('school_id', $user->school_id)->where('learner_id', $learner->id)->first();
            abort_unless($submission, 404);
            abort_unless($submission->submission_status === 'draft', 409, 'Only draft submissions accept uploads.');

            return;
        }
        $learner = $this->access->learner($user);
        abort_unless(DB::table('learner_help_requests')->whereKey($id)->where('school_id', $user->school_id)->where('learner_id', $learner->id)->exists(), 404);
    }
}
