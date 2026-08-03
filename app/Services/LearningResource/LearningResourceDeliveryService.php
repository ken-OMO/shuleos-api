<?php

namespace App\Services\LearningResource;

use App\Core\Security\File\SecureFileStorage;
use App\Models\LearningResource;
use App\Models\LearningResourceVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LearningResourceDeliveryService
{
    public function __construct(private readonly SecureFileStorage $storage, private readonly LearningResourceService $resources) {}

    public function download(User $user, LearningResource $resource, ?LearningResourceVersion $version = null, ?string $learnerId = null, bool $historical = false): BinaryFileResponse
    {
        abort_unless($resource->school_id === $user->school_id && ! $resource->is_deleted, 404);
        if ($resource->publication_status !== 'published') {
            throw new AuthorizationException('Only published resources can be downloaded.');
        }
        $version ??= $resource->versions()->where('version_number', $resource->current_version_number)->firstOrFail();
        if (! $historical && $version->version_number !== $resource->current_version_number) {
            throw new AuthorizationException('Historical versions are restricted.');
        }
        abort_if(! $version->storage_id, 404);
        $dir = storage_path('app/private/resource-delivery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.DIRECTORY_SEPARATOR.Str::random(48);
        $this->storage->decryptToPath($version->storage_id, $path);
        $this->resources->logAccess($user, $resource, $version, 'download', $learnerId);

        return response()->download($path, $version->safe_download_filename, ['Content-Type' => $version->mime_type])->deleteFileAfterSend(true);
    }
}
