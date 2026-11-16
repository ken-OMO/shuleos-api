<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Core\Security\File\FileHash;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\Scanners\ClamAVScanner;
use App\Core\Security\File\Scanners\NullVirusScanner;
use App\Core\Security\File\SecureFileStorage;
use App\Core\Security\File\SecurityValidator;
use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentElectionPhotoService
{
    public function __construct(
        private readonly FileSecurityManager $security,
        private readonly VirusScannerInterface $scanner,
        private readonly FileQuarantine $quarantine,
        private readonly SecureFileStorage $storage,
        private readonly FileHash $hash,
    ) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['photo' => $message]);
    }

    private function actor(User $actor): User
    {
        abort_unless($actor->getAttribute('school_id') !== null, 403);
        $actor = User::withoutGlobalScopes()->where('school_id', $actor->school_id)->whereKey($actor->id)->sharedLock()->firstOrFail();
        app(AuthContextService::class)->assertAccessible($actor);

        return $actor;
    }

    private function owner(User $actor, string $learner, bool $writing = false): object
    {
        $query = DB::table('learners')->where('id', $learner)->where('school_id', $actor->school_id)
            ->where('active', true)->where('is_deleted', false);
        // Readers share ownership protection; uploads serialize version/head changes.
        $row = ($writing ? $query->lockForUpdate() : $query->sharedLock())->first();
        abort_unless($row !== null, 404);
        if ($row->user_id === $actor->id) {
            app(StudentElectionLearnerAccess::class)->assertLearner($actor, $learner);
        } else {
            abort_unless(app(AuthContextService::class)->hasPermission($actor, 'manage_learners'), 403);
        }

        return $row;
    }

    /** @phpstan-impure */
    private function scannerAvailable(): bool
    {
        return $this->scanner->available();
    }

    private function inspect(UploadedFile $file): void
    {
        abort_if($this->scanner instanceof NullVirusScanner || ! $this->scannerAvailable(), 503, 'Photo security scanning is unavailable.');
        abort_unless(app()->environment('testing') || $this->scanner instanceof ClamAVScanner, 503, 'A production photo scanner is required.');
        $report = $this->security->scan($file, FilePolicyFactory::profilePhoto());
        $results = $report->validatorResults();
        foreach ([SecurityValidator::EXTENSION, SecurityValidator::MIME, SecurityValidator::MAGIC_NUMBER, SecurityValidator::VIRUS_SCAN] as $check) {
            if (($results[$check->value] ?? null) !== true) {
                $this->fail('The photo did not pass all required security checks.');
            }
        }
        if ($report->failed() || ! $this->scannerAvailable()) {
            $this->fail('The photo did not pass all required security checks.');
        }
    }

    private function summary(object $row): array
    {
        return ['version' => $row->id, 'revision' => (int) $row->revision,
            'width' => (int) $row->width, 'height' => (int) $row->height, 'mime' => $row->mime];
    }

    /** Stores only normalized image pixels, never the client's filename or EXIF. */
    public function upload(User $authenticated, string $learner, ?string $expected, UploadedFile $file, bool $identityAttested): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Photo file persistence requires an outermost transaction.');
        }
        abort_unless($identityAttested, 422, 'Confirm that this is the named learner profile photo.');
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 5 * 1024 * 1024) {
            $this->fail('Upload a valid JPEG, PNG or WebP photo of at most 5 MB.');
        }
        abort_unless(function_exists('imagecreatefromstring') && function_exists('imagepng'), 503, 'Photo processing is unavailable.');
        $stored = null;
        $callbackComplete = false;
        $quarantineId = null;
        $normalized = null;
        try {
            return DB::transaction(function () use ($authenticated, $learner, $expected, $file, &$stored, &$callbackComplete, &$quarantineId, &$normalized) {
                $actor = $this->actor($authenticated);
                $school = DB::table('schools')->where('id', $actor->school_id)->sharedLock()->firstOrFail();
                abort_if(in_array($school->lifecycle_state, ['read_only', 'suspended', 'locked', 'archived'], true), 403);
                app(StudentElectionCommercialAccess::class)->normal($actor->school_id);
                $this->owner($actor, $learner, true);
                $head = DB::table('student_election_profile_photos')->where('school_id', $actor->school_id)->where('learner_id', $learner)->first();
                abort_unless($head?->version_id === $expected, 409, 'Profile photo changed. Refresh before uploading.');
                $quarantineId = $this->quarantine->quarantine($file, $this->hash->calculateUploadedFile($file));
                $raw = $this->quarantine->retrieve($quarantineId);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($raw);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                if (! is_string($mime) || $extension === null) {
                    $this->fail('Only JPEG, PNG and WebP photos are accepted.');
                }
                $source = new UploadedFile($raw, 'profile.'.$extension, $mime, null, true);
                $this->inspect($source);
                $bytes = file_get_contents($raw);
                if ($bytes === false) {
                    throw new \RuntimeException('Private photo input unavailable.');
                }
                $size = @getimagesizefromstring($bytes);
                if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 4000000) {
                    $this->fail('Use a valid photo no larger than 4096 pixels per side and four million pixels overall.');
                }
                $image = @imagecreatefromstring($bytes);
                if ($image === false) {
                    $this->fail('The photo could not be decoded.');
                }
                $scale = min(1, 1024 / max($size[0], $size[1]));
                $width = max(1, (int) floor($size[0] * $scale));
                $height = max(1, (int) floor($size[1] * $scale));
                $pixels = imagecreatetruecolor($width, $height);
                if ($pixels === false) {
                    imagedestroy($image);
                    $this->fail('Photo processing is unavailable.');
                }
                try {
                    imagealphablending($pixels, false);
                    imagesavealpha($pixels, true);
                    if (! imagecopyresampled($pixels, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1])) {
                        $this->fail('The photo could not be normalized.');
                    }
                    $normalized = tempnam($this->quarantine->directory(), 'election-photo-');
                    if ($normalized === false || ! chmod($normalized, 0600) || ! imagepng($pixels, $normalized, 6)) {
                        throw new \RuntimeException('Private photo normalization failed.');
                    }
                } finally {
                    imagedestroy($image);
                    imagedestroy($pixels);
                }
                if (filesize($normalized) > 5 * 1024 * 1024) {
                    $this->fail('The normalized photo is too large.');
                }
                $this->inspect(new UploadedFile($normalized, 'profile.png', 'image/png', null, true));
                $stored = $this->storage->storePath($normalized, $this->hash->calculatePath($normalized));
                $id = (string) Str::uuid();
                $revision = DB::table('student_election_photo_versions')->where('school_id', $actor->school_id)->where('learner_id', $learner)->count() + 1;
                $row = ['id' => $id, 'school_id' => $actor->school_id, 'learner_id' => $learner, 'revision' => $revision,
                    'storage_id' => $stored['storage_id'], 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'],
                    'width' => $width, 'height' => $height, 'mime' => 'image/png', 'scan_basis' => app()->environment('testing') ? 'test_only' : 'production_scan',
                    'scanner' => mb_substr($this->scanner->name(), 0, 120), 'attested_by' => $actor->id];
                DB::table('student_election_photo_versions')->insert($row);
                DB::table('student_election_profile_photos')->updateOrInsert(['school_id' => $actor->school_id, 'learner_id' => $learner], ['version_id' => $id]);
                if (! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $actor->school_id,
                    'election_id' => null, 'actor_user_id' => $actor->id, 'action' => 'election_profile_photo_versioned', 'entity_type' => 'profile_photo',
                    'entity_id' => $id, 'metadata' => json_encode(['revision' => $revision, 'identity_attested' => true], JSON_THROW_ON_ERROR), 'created_at' => now()])) {
                    throw new \RuntimeException('Photo audit failed.');
                }
                app(StudentElectionCommercialAccess::class)->normal($actor->school_id);
                $callbackComplete = true;

                return $this->summary((object) $row);
            });
        } catch (\Throwable $error) {
            // An uncertain COMMIT must not delete a file that may be referenced.
            if ($stored !== null && ! $callbackComplete) {
                $this->storage->delete($stored['storage_id']);
            }
            throw $error;
        } finally {
            if (is_string($normalized) && is_file($normalized)) {
                unlink($normalized);
            }
            if ($quarantineId !== null && $this->quarantine->exists($quarantineId)) {
                $this->quarantine->delete($quarantineId);
            }
        }
    }

    /** Internal ownership/provenance hook; never serialize the storage fields. */
    public function requireVersion(string $school, string $learner, string $version, bool $current = false): object
    {
        abort_unless(Str::isUuid($version), 404);
        $row = DB::table('student_election_photo_versions')->where('school_id', $school)->where('learner_id', $learner)->where('id', $version)->sharedLock()->first();
        abort_unless($row !== null, 404);
        abort_unless($row->scan_basis === 'production_scan' || ($row->scan_basis === 'test_only' && app()->environment('testing')), 503, 'This photo is not available in this environment.');
        if ($current) {
            abort_unless(DB::table('student_election_profile_photos')->where('school_id', $school)->where('learner_id', $learner)->value('version_id') === $version, 409, 'Use the current verified profile photo.');
        }
        abort_unless($this->storage->exists($row->storage_id)
            && hash_equals($row->stored_hash, $this->hash->calculatePath($this->storage->retrieve($row->storage_id))), 503, 'The verified photo is temporarily unavailable.');

        return $row;
    }

    public function profile(User $authenticated, string $learner): ?array
    {
        return DB::transaction(function () use ($authenticated, $learner) {
            $actor = $this->actor($authenticated);
            $this->owner($actor, $learner);
            $head = DB::table('student_election_profile_photos')->where('school_id', $actor->school_id)->where('learner_id', $learner)->first();

            return $head ? $this->summary($this->requireVersion($actor->school_id, $learner, $head->version_id)) : null;
        });
    }

    private function response(object $photo): BinaryFileResponse
    {
        $destination = tempnam($this->quarantine->directory(), 'election-view-');
        if ($destination === false) {
            throw new \RuntimeException('Private photo rendering failed.');
        }
        try {
            $this->storage->decryptToPath($photo->storage_id, $destination);
            if (! hash_equals($photo->source_hash, $this->hash->calculatePath($destination))) {
                $this->fail('The verified photo could not be authenticated.');
            }

            return response()->file($destination, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'])->setPrivate()->setContentDisposition('inline', 'profile.png')->deleteFileAfterSend(true);
        } catch (\Throwable $error) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $error;
        }
    }

    public function profileImage(User $authenticated, string $learner): BinaryFileResponse
    {
        return DB::transaction(function () use ($authenticated, $learner) {
            $actor = $this->actor($authenticated);
            $this->owner($actor, $learner);
            $head = DB::table('student_election_profile_photos')->where('school_id', $actor->school_id)->where('learner_id', $learner)->first();
            abort_unless($head !== null, 404);

            return $this->response($this->requireVersion($actor->school_id, $learner, $head->version_id));
        });
    }

    public function applicationImage(User $actor, string $election, string $application): BinaryFileResponse
    {
        return DB::transaction(function () use ($actor, $election, $application) {
            $view = app(StudentElectionApplicationService::class)->view($actor, $election, $application);
            abort_unless($view['photo_version'] !== null, 404);
            $a = DB::table('student_election_applications')->where('id', $application)->where('school_id', $actor->school_id)->firstOrFail();

            return $this->response($this->requireVersion($a->school_id, $a->learner_id, $view['photo_version']));
        });
    }

    public function campaignImage(User $actor, string $election, string $position, string $candidate): BinaryFileResponse
    {
        return DB::transaction(function () use ($actor, $election, $position, $candidate) {
            $actor = $this->actor($actor);
            $e = StudentElection::current()->where('school_id', $actor->school_id)->whereKey($election)->sharedLock()->firstOrFail();
            $timezone = app(StudentElectionClassroomAccess::class)->timezone($e);
            abort_unless($e->status === 'campaigning' && ! $e->integrity_hold
                && now($timezone)->toDateString() < $e->voting_opens_at->copy()->setTimezone($timezone)->toDateString(), 403);
            $c = app(StudentElectionReadService::class)->candidates($actor, $election, $position)->firstWhere('id', $candidate);
            abort_unless($c !== null && $c->photo_version_id !== null, 404);
            $a = DB::table('student_election_applications')->where('school_id', $actor->school_id)->where('election_id', $election)->where('candidate_id', $candidate)->where('status', 'approved')->firstOrFail();
            $v = DB::table('student_election_application_versions')->where('id', $a->version_id)->where('photo_version_id', $c->photo_version_id)->firstOrFail();

            return $this->response($this->requireVersion($actor->school_id, $a->learner_id, $v->photo_version_id));
        });
    }
}
