<?php

namespace App\Services\Homework;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\HomeworkSubmission;
use App\Models\HomeworkSubmissionFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class HomeworkSubmissionFileService
{
    public function __construct(private FileSecurityManager $security, private FileQuarantine $quarantine, private SecureFileStorage $storage, private HomeworkLearnerService $learners) {}

    public function upload(User $u, string $assignment, UploadedFile $file): HomeworkSubmissionFile
    {
        $record = $this->learners->record($u, $assignment);
        $submission = $record->submissions()->where('submission_status', 'draft')->latest('attempt_number')->firstOrFail();
        $report = $this->security->scan($file, FilePolicyFactory::homeworkSubmission());
        if ($report->failed()) {
            throw ValidationException::withMessages(['file' => 'File failed the homework submission security policy.']);
        }$qid = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $qid);
        try {
            return DB::transaction(fn () => HomeworkSubmissionFile::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'submission_id' => $submission->id, 'storage_id' => $stored['storage_id'], 'original_filename' => $file->getClientOriginalName(), 'safe_download_filename' => Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension()), 'mime_type' => $file->getMimeType(), 'extension' => strtolower($file->getClientOriginalExtension()), 'source_size' => $file->getSize(), 'stored_size' => $stored['size'], 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'], 'encrypted' => true, 'uploaded_at' => now(), 'created_by' => $u->id]));
        } catch (Throwable $e) {
            $this->storage->delete($stored['storage_id']);
            throw $e;
        }
    }

    public function learnerDownload(User $u, string $assignment, string $file): BinaryFileResponse
    {
        $record = $this->learners->record($u, $assignment);
        $f = HomeworkSubmissionFile::whereKey($file)->where('school_id', $u->school_id)->whereIn('submission_id', $record->submissions()->select('id'))->firstOrFail();

        return $this->response($f);
    }

    public function teacherDownload(User $u, string $assignment, string $submission, string $file, HomeworkAssignmentService $assignments): BinaryFileResponse
    {
        $a = $assignments->ownQuery($u)->whereKey($assignment)->firstOrFail();
        $s = HomeworkSubmission::whereKey($submission)->where('school_id', $u->school_id)->where('assignment_id', $a->id)->firstOrFail();
        $f = HomeworkSubmissionFile::whereKey($file)->where('submission_id', $s->id)->where('school_id', $u->school_id)->firstOrFail();

        return $this->response($f);
    }

    public function delete(User $u, string $assignment, string $file): void
    {
        $record = $this->learners->record($u, $assignment);
        DB::transaction(function () use ($u, $record, $file) {
            $f = HomeworkSubmissionFile::whereKey($file)->where('school_id', $u->school_id)->whereIn('submission_id', $record->submissions()->where('submission_status', 'draft')->select('id'))->lockForUpdate()->firstOrFail();
            $storage = $f->storage_id;
            $f->delete();
            $this->storage->delete($storage);
        });
    }

    private function response(HomeworkSubmissionFile $file): BinaryFileResponse
    {
        abort_unless(hash_equals($file->stored_hash, $this->storage->hash($file->storage_id)), 409, 'Stored file integrity check failed.');
        $dir = storage_path('app/private/homework-delivery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$path = $dir.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($file->storage_id, $path);

        return response()->download($path, $file->safe_download_filename, ['Content-Type' => $file->mime_type])->deleteFileAfterSend(true);
    }
}
