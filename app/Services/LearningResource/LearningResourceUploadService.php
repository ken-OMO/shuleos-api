<?php

namespace App\Services\LearningResource;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\LearningResource;
use App\Models\LearningResourceVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LearningResourceUploadService
{
    public function __construct(private readonly FileSecurityManager $security, private readonly FileQuarantine $quarantine, private readonly SecureFileStorage $storage, private readonly LearningResourceService $resources) {}

    public function create(string $s, string $u, array $d, UploadedFile $f): LearningResource
    {
        $this->resources->validateScope($s, $u, $d);
        $report = $this->security->scan($f, FilePolicyFactory::learningResource());
        if ($report->failed()) {
            throw ValidationException::withMessages(['file' => 'File failed the secure learning-resource policy.']);
        }$qid = $this->quarantine->quarantine($f);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $qid);
        try {
            return DB::transaction(function () use ($s, $u, $d, $f, $stored) {
                $r = LearningResource::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'source_type' => 'uploaded_file', 'publication_status' => 'draft', 'uploaded_by' => $u, 'current_version_number' => 1]);
                LearningResourceVersion::create(['id' => (string) Str::uuid(), 'school_id' => $s, 'resource_id' => $r->id, 'version_number' => 1, 'storage_id' => $stored['storage_id'], 'original_filename' => $f->getClientOriginalName(), 'safe_download_filename' => Str::slug(pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($f->getClientOriginalExtension()), 'mime_type' => $f->getMimeType(), 'extension' => strtolower($f->getClientOriginalExtension()), 'source_size' => $f->getSize(), 'stored_size' => $stored['size'], 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'], 'encrypted' => true, 'created_by' => $u, 'created_at' => now()]);

                return $r->load('currentVersion', 'category', 'learningArea', 'grade', 'stream');
            });
        } catch (Throwable$e) {
            $this->storage->delete($stored['storage_id']);
            throw $e;
        }
    }
}
