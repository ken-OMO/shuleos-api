<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Core\Security\File\SecureFileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReportBrandingAssetService
{
    public function read(string $schoolId, string $name): ?array
    {
        $types = match ($name) {
            'logo' => ['report_card_logo', 'logo'],
            'principal_signature' => ['principal_signature'],
            default => throw new \InvalidArgumentException('Unsupported report asset.'),
        };

        $asset = null;
        foreach ($types as $type) {
            $asset = DB::table('administrator_branding_assets')
                ->where('school_id', $schoolId)->where('asset_type', $type)
                ->where('status', 'approved')->whereNull('archived_at')
                ->orderByDesc('approved_at')->orderByDesc('id')->first();
            if ($asset !== null) {
                break;
            }
        }

        if ($asset === null) {
            return null;
        }

        $temporary = null;

        try {
            // Stored metadata describes ciphertext, including encryption overhead.
            if ((int) $asset->size < 1 || (int) $asset->size > 5242880) {
                throw new \RuntimeException('Invalid branding asset size.');
            }

            $storage = app(SecureFileStorage::class);
            $storedPath = $storage->retrieve($asset->storage_id);
            clearstatcache(true, $storedPath);
            $storedSize = filesize($storedPath);
            $storedHash = hash_file('sha256', $storedPath);

            if ($storedSize === false
                || $storedSize !== (int) $asset->size
                || $storedHash === false
                || ! hash_equals((string) $asset->stored_hash, $storedHash)) {
                throw new \RuntimeException('Stored branding integrity verification failed.');
            }

            $directory = storage_path('app/private/report-branding');
            if (! is_dir($directory)
                && ! mkdir($directory, 0700, true)
                && ! is_dir($directory)) {
                throw new \RuntimeException('Cannot create private branding directory.');
            }

            $temporary = tempnam($directory, 'report-');
            if ($temporary === false) {
                $temporary = null;
                throw new \RuntimeException('Cannot create private branding file.');
            }
            if (! chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot secure branding file.');
            }

            $storage->decryptToPath($asset->storage_id, $temporary);

            $bytes = file_get_contents($temporary, false, null, 0, 4194305);
            if ($bytes === false
                || strlen($bytes) < 1
                || strlen($bytes) > 4194304
                || ! hash_equals((string) $asset->source_hash, hash('sha256', $bytes))) {
                throw new \RuntimeException('Branding integrity verification failed.');
            }

            $info = @getimagesizefromstring($bytes);
            $mime = $info['mime'] ?? null;
            if (! $info || $info[0] > 4000 || $info[1] > 4000
                || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new \RuntimeException('Invalid branding image.');
            }

            if ($mime === 'image/webp') {
                if (! function_exists('imagecreatefromstring')
                    || ! function_exists('imagepng')) {
                    throw new \RuntimeException('WebP conversion requires GD.');
                }
                $image = @imagecreatefromstring($bytes);
                if ($image === false) {
                    throw new \RuntimeException('WebP conversion failed.');
                }
                try {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    if (! imagepng($image, $temporary)) {
                        throw new \RuntimeException('PNG conversion failed.');
                    }
                    $bytes = file_get_contents($temporary, false, null, 0, 2097153);
                    $mime = 'image/png';
                } finally {
                    imagedestroy($image);
                }
            }

            if ($bytes === false || strlen($bytes) > 2097152) {
                throw new \RuntimeException('Captured branding exceeds 2 MB.');
            }

            return [
                'status' => 'captured',
                'mime' => $mime,
                'sha256' => hash('sha256', $bytes),
                'base64' => base64_encode($bytes),
            ];
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'branding' => 'The approved report branding could not be read or exceeds the supported limits. Replace the asset before publication.',
            ]);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
