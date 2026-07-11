<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Detects duplicate files using cryptographic hashes.
 *
 * This service does not decide where hashes are stored.
 * It compares an uploaded file against known hashes
 * supplied by the calling repository or service.
 */
final readonly class DuplicateDetector
{
    /**
     * Create the duplicate detector.
     */
    public function __construct(
        private FileHash $hasher
    ) {}

    /**
     * Determine whether an uploaded file matches
     * any known cryptographic hash.
     *
     * @param  iterable<string>  $knownHashes
     */
    public function isDuplicate(
        UploadedFile $file,
        iterable $knownHashes
    ): bool {
        $hash = $this->hasher->calculateUploadedFile($file);

        return $this->containsHash(
            $hash,
            $knownHashes
        );
    }

    /**
     * Find the matching known hash.
     *
     * Returns null when no duplicate exists.
     *
     * @param  iterable<string>  $knownHashes
     */
    public function findDuplicate(
        UploadedFile $file,
        iterable $knownHashes
    ): ?string {
        $hash = $this->hasher->calculateUploadedFile($file);

        foreach ($knownHashes as $knownHash) {
            $knownHash = $this->normalizeHash($knownHash);

            if (hash_equals($knownHash, $hash)) {
                return $knownHash;
            }
        }

        return null;
    }

    /**
     * Determine whether a calculated hash exists
     * within the known hash collection.
     *
     * @param  iterable<string>  $knownHashes
     */
    public function containsHash(
        string $hash,
        iterable $knownHashes
    ): bool {
        $hash = $this->normalizeHash($hash);

        foreach ($knownHashes as $knownHash) {
            if (
                hash_equals(
                    $this->normalizeHash($knownHash),
                    $hash
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the fingerprint used for
     * duplicate comparison.
     */
    public function fingerprint(
        UploadedFile $file
    ): string {
        return $this->hasher->calculateUploadedFile($file);
    }

    /**
     * Return the hashing algorithm.
     */
    public function algorithm(): string
    {
        return $this->hasher->algorithm();
    }

    /**
     * Normalize a cryptographic hash.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeHash(
        string $hash
    ): string {
        $hash = strtolower(trim($hash));

        if ($hash === '') {
            throw new InvalidArgumentException(
                'Duplicate comparison hash cannot be empty.'
            );
        }

        if (preg_match('/^[a-f0-9]+$/', $hash) !== 1) {
            throw new InvalidArgumentException(
                'Duplicate comparison hash must be hexadecimal.'
            );
        }

        return $hash;
    }
}
