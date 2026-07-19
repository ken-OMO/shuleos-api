<?php

namespace App\Services\ParentPortal;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\ParentPortalAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ParentPortalAttachmentService
{
    private const CONTEXTS = ['message', 'appointment_evidence', 'consent_document', 'contact_verification', 'profile_image', 'payment_issue'];

    public function __construct(
        private FileSecurityManager $security,
        private FileQuarantine $quarantine,
        private SecureFileStorage $storage,
        private ParentPortalAccessService $access,
        private ParentPortalAuditService $audit,
    ) {}

    public function upload(User $user, string $context, ?string $contextId, UploadedFile $file): ParentPortalAttachment
    {
        $this->access->parent($user);
        abort_unless(in_array($context, self::CONTEXTS, true), 422);
        $policy = $context === 'profile_image' ? FilePolicyFactory::profilePhoto() : FilePolicyFactory::homeworkSubmission();
        if ($this->security->scan($file, $policy)->failed()) {
            throw ValidationException::withMessages(['file' => 'The file failed the secure parent upload policy.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $duplicate = ParentPortalAttachment::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('source_hash', $hash)->whereNotIn('status', ['rejected', 'archived'])->first();
        if ($duplicate) {
            return $duplicate;
        }
        $quarantineId = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $quarantineId);
        try {
            $attachment = ParentPortalAttachment::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id,
                'context_type' => $context, 'context_id' => $contextId, 'original_filename' => $file->getClientOriginalName(),
                'safe_filename' => Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension()),
                'mime_type' => $file->getMimeType(), 'extension' => strtolower($file->getClientOriginalExtension()),
                'source_size' => $file->getSize(), 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'],
                'storage_id' => $stored['storage_id'], 'status' => 'pending_scan',
            ]);
            $this->audit->record($user, 'parent_attachment_uploaded', null, 'parent_portal_attachment', $attachment->id, ['context' => $context]);

            return $attachment;
        } catch (Throwable $exception) {
            $this->storage->delete($stored['storage_id']);
            throw $exception;
        }
    }

    public function find(User $user, string $id): ParentPortalAttachment
    {
        $this->access->parent($user);

        return ParentPortalAttachment::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('archived_at')->firstOrFail();
    }

    public function download(User $user, string $id): BinaryFileResponse
    {
        $file = $this->find($user, $id);
        abort_unless(in_array($file->status, ['clean', 'attached'], true), 409, 'Only clean files can be downloaded.');
        abort_unless(hash_equals($file->stored_hash, $this->storage->hash($file->storage_id)), 409, 'Stored file integrity check failed.');
        $directory = storage_path('app/private/parent-delivery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($file->storage_id, $path);
        $this->audit->record($user, 'parent_attachment_downloaded', null, 'parent_portal_attachment', $id);

        return response()->download($path, $file->safe_filename, ['Content-Type' => $file->mime_type])->deleteFileAfterSend(true);
    }

    public function archive(User $user, string $id): void
    {
        $file = $this->find($user, $id);
        abort_if($file->status === 'attached' || $file->attached_at, 409, 'Attached evidence cannot be removed.');
        $file->update(['status' => 'archived', 'archived_at' => now()]);
    }
}
