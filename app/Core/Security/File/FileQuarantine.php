<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Secure file quarantine service.
 *
 * Files are placed in a private directory before
 * they are approved for permanent secure storage.
 */
final class FileQuarantine
{
    /**
     * Private quarantine directory.
     */
    private readonly string $directory;

    /**
     * Create the quarantine service.
     */
    public function __construct(
        private readonly FileHash $hasher,
        ?string $directory = null
    ) {
        $this->directory = $directory
            ?? storage_path('app/private/quarantine');

        $this->ensureDirectoryExists();
    }

    /**
     * Copy an uploaded file into quarantine.
     *
     * Returns the generated quarantine identifier.
     *
     * @throws RuntimeException
     */
    public function quarantine(
        UploadedFile $file,
        ?string $expectedHash = null
    ): string {
        $sourcePath = $file->getRealPath();

        if (
            $sourcePath === false
            || ! is_file($sourcePath)
            || ! is_readable($sourcePath)
        ) {
            throw new RuntimeException(
                'The uploaded file cannot be read for quarantine.'
            );
        }

        $quarantineId = bin2hex(random_bytes(32));
        $temporaryPath = $this->directory
            .DIRECTORY_SEPARATOR
            .$quarantineId
            .'.pending';

        $finalPath = $this->path($quarantineId);

        try {
            if (! copy($sourcePath, $temporaryPath)) {
                throw new RuntimeException(
                    'Failed to copy the uploaded file into quarantine.'
                );
            }

            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException(
                    'Failed to apply secure quarantine permissions.'
                );
            }

            $sourceHash = $expectedHash !== null
                ? strtolower(trim($expectedHash))
                : $this->hasher->calculateUploadedFile($file);

            $quarantineHash = $this->hasher->calculatePath(
                $temporaryPath
            );

            if (! hash_equals($sourceHash, $quarantineHash)) {
                throw new RuntimeException(
                    'Quarantined file integrity verification failed.'
                );
            }

            if (! rename($temporaryPath, $finalPath)) {
                throw new RuntimeException(
                    'Failed to finalize the quarantined file.'
                );
            }

            return $quarantineId;
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            if (is_file($finalPath)) {
                @unlink($finalPath);
            }

            throw new RuntimeException(
                'File quarantine failed: '.$exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * Determine whether a quarantined file exists.
     */
    public function exists(
        string $quarantineId
    ): bool {
        return is_file(
            $this->path($quarantineId)
        );
    }

    /**
     * Return the absolute quarantined file path.
     *
     * @throws RuntimeException
     */
    public function retrieve(
        string $quarantineId
    ): string {
        $path = $this->path($quarantineId);

        if (! is_file($path)) {
            throw new RuntimeException(
                'The requested quarantined file does not exist.'
            );
        }

        if (! is_readable($path)) {
            throw new RuntimeException(
                'The requested quarantined file is not readable.'
            );
        }

        return $path;
    }

    /**
     * Calculate the quarantined file hash.
     */
    public function hash(
        string $quarantineId
    ): string {
        return $this->hasher->calculatePath(
            $this->retrieve($quarantineId)
        );
    }

    /**
     * Permanently remove a quarantined file.
     *
     * @throws RuntimeException
     */
    public function delete(
        string $quarantineId
    ): void {
        $path = $this->path($quarantineId);

        if (! is_file($path)) {
            return;
        }

        if (! unlink($path)) {
            throw new RuntimeException(
                'Failed to delete the quarantined file.'
            );
        }
    }

    /**
     * Return the quarantine directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Build a safe quarantined file path.
     *
     * @throws InvalidArgumentException
     */
    private function path(
        string $quarantineId
    ): string {
        $quarantineId = strtolower(
            trim($quarantineId)
        );

        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $quarantineId
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid quarantine identifier.'
            );
        }

        return $this->directory
            .DIRECTORY_SEPARATOR
            .$quarantineId
            .'.quarantine';
    }

    /**
     * Ensure the private quarantine directory exists.
     *
     * @throws RuntimeException
     */
    private function ensureDirectoryExists(): void
    {
        if (
            ! is_dir($this->directory)
            && ! mkdir(
                $this->directory,
                0700,
                true
            )
            && ! is_dir($this->directory)
        ) {
            throw new RuntimeException(
                'Failed to create the quarantine directory.'
            );
        }

        if (! is_writable($this->directory)) {
            throw new RuntimeException(
                'The quarantine directory is not writable.'
            );
        }
    }
}
