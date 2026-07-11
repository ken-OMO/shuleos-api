<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cryptographic file hashing service.
 *
 * Generates and verifies secure hashes for:
 *
 * - Integrity verification
 * - Duplicate detection
 * - Audit logging
 * - Secure storage validation
 */
final readonly class FileHash
{
    /**
     * Default hashing algorithm.
     */
    public const DEFAULT_ALGORITHM = 'sha256';

    /**
     * Approved cryptographic hashing algorithms.
     *
     * @var list<string>
     */
    private const ALLOWED_ALGORITHMS = [
        'sha256',
        'sha384',
        'sha512',
    ];

    /**
     * Create a file hashing service.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private string $algorithm = self::DEFAULT_ALGORITHM
    ) {
        if (! in_array($this->algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported hashing algorithm [%s]. Allowed algorithms: %s.',
                    $this->algorithm,
                    implode(', ', self::ALLOWED_ALGORITHMS)
                )
            );
        }
    }

    /**
     * Calculate the hash of an uploaded file.
     *
     * @throws RuntimeException
     */
    public function calculateUploadedFile(
        UploadedFile $file
    ): string {
        $path = $file->getRealPath();

        if ($path === false || $path === '') {
            throw new RuntimeException(
                'Unable to resolve the uploaded file path.'
            );
        }

        return $this->calculatePath($path);
    }

    /**
     * Calculate the hash of a file path.
     *
     * @throws RuntimeException
     */
    public function calculatePath(
        string $path
    ): string {
        if (! is_file($path)) {
            throw new RuntimeException(
                sprintf('File does not exist [%s].', $path)
            );
        }

        if (! is_readable($path)) {
            throw new RuntimeException(
                sprintf('File is not readable [%s].', $path)
            );
        }

        $hash = hash_file(
            $this->algorithm,
            $path
        );

        if ($hash === false) {
            throw new RuntimeException(
                sprintf(
                    'Failed to calculate the %s hash for [%s].',
                    $this->algorithm,
                    $path
                )
            );
        }

        return strtolower($hash);
    }

    /**
     * Verify an uploaded file against an expected hash.
     */
    public function verifyUploadedFile(
        UploadedFile $file,
        string $expectedHash
    ): bool {
        return hash_equals(
            $this->normalizeHash($expectedHash),
            $this->calculateUploadedFile($file)
        );
    }

    /**
     * Verify a file path against an expected hash.
     */
    public function verifyPath(
        string $path,
        string $expectedHash
    ): bool {
        return hash_equals(
            $this->normalizeHash($expectedHash),
            $this->calculatePath($path)
        );
    }

    /**
     * Return the configured algorithm.
     */
    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Return approved hashing algorithms.
     *
     * @return list<string>
     */
    public static function allowedAlgorithms(): array
    {
        return self::ALLOWED_ALGORITHMS;
    }

    /**
     * Normalize and validate a supplied hash.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeHash(
        string $hash
    ): string {
        $hash = strtolower(trim($hash));

        if ($hash === '') {
            throw new InvalidArgumentException(
                'Expected file hash cannot be empty.'
            );
        }

        $expectedLength = match ($this->algorithm) {
            'sha256' => 64,
            'sha384' => 96,
            'sha512' => 128,
        };

        if (
            strlen($hash) !== $expectedLength
            || preg_match('/^[a-f0-9]+$/', $hash) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid %s hash value.',
                    $this->algorithm
                )
            );
        }

        return $hash;
    }
}
