<?php

namespace App\Services\TeacherPortal;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\TeacherAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TeacherPortalAttachmentService
{
    private const CONTEXTS = ['lesson_plan', 'lesson_note', 'record_of_work', 'homework', 'learning_resource', 'mark_correction', 'profile_image'];

    public function __construct(private FileSecurityManager $security, private FileQuarantine $quarantine, private SecureFileStorage $storage, private TeacherPortalAccessService $access) {}

    public function upload(User $user, string $context, ?string $contextId, UploadedFile $file): TeacherAttachment
    {
        abort_unless(in_array($context, self::CONTEXTS, true), 422);
        $teacher = $this->access->teacher($user);
        $this->assertContext($user, $context, $contextId);
        $policy = match ($context) {
            'profile_image' => FilePolicyFactory::profilePhoto(),
            'lesson_note' => FilePolicyFactory::lessonNote(),
            'lesson_plan', 'record_of_work' => FilePolicyFactory::curriculumDocument(),
            'learning_resource', 'homework' => FilePolicyFactory::learningResource(),
            default => FilePolicyFactory::staffDocument(),
        };
        if ($this->security->scan($file, $policy)->failed()) {
            throw ValidationException::withMessages(['file' => 'The file failed the secure teacher upload policy.']);
        }
        $sourceHash = hash_file('sha256', $file->getRealPath());
        $duplicate = TeacherAttachment::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('source_hash', $sourceHash)->whereNotIn('status', ['rejected', 'archived'])->first();
        if ($duplicate) {
            return $duplicate;
        }
        $quarantineId = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $quarantineId);
        try {
            return TeacherAttachment::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'teacher_id' => $teacher->id, 'context_type' => $context, 'context_id' => $contextId, 'original_filename' => $file->getClientOriginalName(), 'safe_filename' => Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension()), 'mime_type' => $file->getMimeType(), 'extension' => strtolower($file->getClientOriginalExtension()), 'source_size' => $file->getSize(), 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'], 'storage_id' => $stored['storage_id'], 'status' => config('teacher_portal_phase_two.upload_scan_trusted') ? 'clean' : 'pending_scan']);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['storage_id']);
            throw $exception;
        }
    }

    public function find(User $user, string $id): TeacherAttachment
    {
        $this->access->teacher($user);

        return TeacherAttachment::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereKey($id)->firstOrFail();
    }

    public function download(User $user, string $id): BinaryFileResponse
    {
        $file = $this->find($user, $id);
        abort_unless(in_array($file->status, ['clean', 'attached'], true), 409, 'Only clean files can be downloaded.');
        abort_unless(hash_equals($file->stored_hash, $this->storage->hash($file->storage_id)), 409, 'Stored file integrity check failed.');
        $directory = storage_path('app/private/teacher-delivery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($file->storage_id, $path);

        return response()->download($path, $file->safe_filename, ['Content-Type' => $file->mime_type])->deleteFileAfterSend(true);
    }

    public function archive(User $user, string $id): void
    {
        $file = $this->find($user, $id);
        abort_if($file->status === 'attached', 409, 'Attached workflow evidence cannot be destructively removed.');
        $file->update(['status' => 'archived']);
    }

    private function assertContext(User $user, string $type, ?string $id): void
    {
        if ($type === 'profile_image') {
            abort_if($id, 422);

            return;
        }
        abort_unless($id, 422);
        if ($type === 'mark_correction') {
            abort_unless(DB::table('mark_correction_requests')->where('id', $id)->where('school_id', $user->school_id)->where('requested_by', $user->id)->exists(), 404);

            return;
        }
        $table = ['lesson_plan' => 'lesson_plans', 'lesson_note' => 'lesson_notes', 'record_of_work' => 'records_of_work', 'homework' => 'homework_assignments', 'learning_resource' => 'learning_resources'][$type];
        $record = DB::table($table)->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($record, 404);
        if ($type === 'learning_resource') {
            abort_unless($record->uploaded_by === $user->id, 403);

            return;
        }
        $assignmentId = match ($type) {
            'lesson_plan', 'homework' => $record->teacher_assignment_id,
            'lesson_note', 'record_of_work' => DB::table('lesson_plans')->where('id', $record->lesson_plan_id)->value('teacher_assignment_id'),
        };
        $this->access->requireAssignment($user, $assignmentId);
    }
}
