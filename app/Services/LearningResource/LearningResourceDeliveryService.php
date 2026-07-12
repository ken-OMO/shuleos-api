<?php

namespace App\Services\LearningResource;

use App\Core\Security\File\SecureFileStorage;
use App\Models\LearningResource;
use App\Models\LearningResourceVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LearningResourceDeliveryService
{
    public function __construct(private readonly SecureFileStorage $storage) {}

    public function download(User $u, string $id): BinaryFileResponse
    {
        $r = LearningResource::current()->whereKey($id)->where('school_id', $u->school_id)->where('publication_status', 'published')->firstOrFail();
        $v = LearningResourceVersion::where('resource_id', $r->id)->where('version_number', $r->current_version_number)->firstOrFail();
        abort_if($r->source_type !== 'uploaded_file' || ! $v->storage_id, 404);
        $dir = storage_path('app/private/resource-delivery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$path = $dir.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($v->storage_id, $path);
        DB::table('learning_resource_access_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'resource_id' => $r->id, 'version_id' => $v->id, 'user_id' => $u->id, 'learner_id' => $u->learner?->id, 'action' => 'download', 'occurred_at' => now()]);

        return response()->download($path, $v->safe_download_filename, ['Content-Type' => $v->mime_type])->deleteFileAfterSend(true);
    }
}
