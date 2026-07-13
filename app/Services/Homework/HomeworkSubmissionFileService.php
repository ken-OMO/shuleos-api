<?php

namespace App\Services\Homework;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\HomeworkSubmissionFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
}
