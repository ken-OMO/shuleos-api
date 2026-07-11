<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Secure encrypted file storage.
 *
 * Stores approved files outside the public directory using:
 *
 * - Random storage identifiers
 * - Authenticated streaming encryption
 * - XChaCha20-Poly1305
 * - SHA-256 integrity verification
 * - Restrictive filesystem permissions
 */
final class SecureFileStorage
{
    /**
     * Encrypted file format identifier.
     */
    private const FILE_MAGIC = 'SHULEOS1';

    /**
     * Stream processing size.
     */
    private const CHUNK_SIZE = 1024 * 1024;

    /**
     * Private storage directory.
     */
    private readonly string $directory;

    /**
     * Binary encryption key.
     */
    private readonly string $encryptionKey;

    /**
     * Create secure storage.
     *
     * @throws RuntimeException
     */
    public function __construct(
        private readonly FileHash $hasher,
        ?string $directory = null,
        ?string $encryptionKey = null
    ) {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException(
                'The PHP Sodium extension is required for secure file storage.'
            );
        }

        $this->directory = $directory
            ?? storage_path('app/private/secure-files');

        $configuredKey = $encryptionKey
            ?? config('security.file_storage.key')
            ?? config('app.key');

        if (
            ! is_string($configuredKey)
            || trim($configuredKey) === ''
        ) {
            throw new RuntimeException(
                'A secure file-storage encryption key is required.'
            );
        }

        $this->encryptionKey = $this->deriveKey(
            $configuredKey
        );

        $this->ensureDirectoryExists();
    }

    /**
     * Store a quarantined file securely.
     *
     * @return array{
     *     storage_id:string,
     *     encrypted:bool,
     *     source_hash:string,
     *     stored_hash:string,
     *     size:int
     * }
     *
     * @throws RuntimeException
     */
    public function storeFromQuarantine(
        FileQuarantine $quarantine,
        string $quarantineId,
        bool $deleteAfterStorage = true
    ): array {
        $sourcePath = $quarantine->retrieve(
            $quarantineId
        );

        $sourceHash = $this->hasher->calculatePath(
            $sourcePath
        );

        $stored = $this->storePath(
            sourcePath: $sourcePath,
            expectedHash: $sourceHash
        );

        if ($deleteAfterStorage) {
            $quarantine->delete(
                $quarantineId
            );
        }

        return $stored;
    }

    /**
     * Encrypt and store a file path securely.
     *
     * @return array{
     *     storage_id:string,
     *     encrypted:bool,
     *     source_hash:string,
     *     stored_hash:string,
     *     size:int
     * }
     *
     * @throws RuntimeException
     */
    public function storePath(
        string $sourcePath,
        ?string $expectedHash = null
    ): array {
        $this->assertReadableFile(
            $sourcePath
        );

        $sourceHash = $this->hasher->calculatePath(
            $sourcePath
        );

        if (
            $expectedHash !== null
            && ! hash_equals(
                strtolower(trim($expectedHash)),
                $sourceHash
            )
        ) {
            throw new RuntimeException(
                'Source file integrity verification failed before storage.'
            );
        }

        $storageId = bin2hex(
            random_bytes(32)
        );

        $temporaryPath = $this->directory
            . DIRECTORY_SEPARATOR
            . $storageId
            . '.pending';

        $finalPath = $this->path(
            $storageId
        );

        try {
            $this->encryptFile(
                sourcePath: $sourcePath,
                destinationPath: $temporaryPath
            );

            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException(
                    'Failed to apply secure file permissions.'
                );
            }

            if (! rename($temporaryPath, $finalPath)) {
                throw new RuntimeException(
                    'Failed to finalize secure file storage.'
                );
            }

            $storedHash = $this->hasher->calculatePath(
                $finalPath
            );

            $size = filesize(
                $finalPath
            );

            if ($size === false) {
                throw new RuntimeException(
                    'Failed to determine stored file size.'
                );
            }

            return [
                'storage_id' => $storageId,
                'encrypted' => true,
                'source_hash' => $sourceHash,
                'stored_hash' => $storedHash,
                'size' => $size,
            ];
        } catch (Throwable $exception) {
            $this->deletePathSilently(
                $temporaryPath
            );

            $this->deletePathSilently(
                $finalPath
            );

            throw new RuntimeException(
                'Secure file storage failed: '
                . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * Decrypt a stored file into a destination path.
     *
     * The destination should be a temporary private path.
     *
     * @throws RuntimeException
     */
    public function decryptToPath(
        string $storageId,
        string $destinationPath
    ): void {
        $sourcePath = $this->retrieve(
            $storageId
        );

        $destinationDirectory = dirname(
            $destinationPath
        );

        if (
            ! is_dir($destinationDirectory)
            && ! mkdir(
                $destinationDirectory,
                0700,
                true
            )
            && ! is_dir($destinationDirectory)
        ) {
            throw new RuntimeException(
                'Failed to create the decryption destination directory.'
            );
        }

        try {
            $this->decryptFile(
                sourcePath: $sourcePath,
                destinationPath: $destinationPath
            );

            if (! chmod($destinationPath, 0600)) {
                throw new RuntimeException(
                    'Failed to secure the decrypted file permissions.'
                );
            }
        } catch (Throwable $exception) {
            $this->deletePathSilently(
                $destinationPath
            );

            throw new RuntimeException(
                'Secure file decryption failed: '
                . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * Determine whether a stored file exists.
     */
    public function exists(
        string $storageId
    ): bool {
        return is_file(
            $this->path($storageId)
        );
    }

    /**
     * Retrieve the private encrypted file path.
     *
     * @throws RuntimeException
     */
    public function retrieve(
        string $storageId
    ): string {
        $path = $this->path(
            $storageId
        );

        if (! is_file($path)) {
            throw new RuntimeException(
                'The requested secure file does not exist.'
            );
        }

        if (! is_readable($path)) {
            throw new RuntimeException(
                'The requested secure file is not readable.'
            );
        }

        return $path;
    }

    /**
     * Calculate the encrypted stored-file hash.
     */
    public function hash(
        string $storageId
    ): string {
        return $this->hasher->calculatePath(
            $this->retrieve($storageId)
        );
    }

    /**
     * Permanently remove a stored file.
     *
     * @throws RuntimeException
     */
    public function delete(
        string $storageId
    ): void {
        $path = $this->path(
            $storageId
        );

        if (! is_file($path)) {
            return;
        }

        if (! unlink($path)) {
            throw new RuntimeException(
                'Failed to delete the securely stored file.'
            );
        }
    }

    /**
     * Return the secure storage directory.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Encrypt a file using Sodium secret-stream encryption.
     *
     * @throws RuntimeException
     */
    private function encryptFile(
        string $sourcePath,
        string $destinationPath
    ): void {
        $input = fopen(
            $sourcePath,
            'rb'
        );

        if ($input === false) {
            throw new RuntimeException(
                'Failed to open the source file for encryption.'
            );
        }

        $output = fopen(
            $destinationPath,
            'wb'
        );

        if ($output === false) {
            fclose($input);

            throw new RuntimeException(
                'Failed to open the secure storage destination.'
            );
        }

        try {
            [$state, $header] =
                sodium_crypto_secretstream_xchacha20poly1305_init_push(
                    $this->encryptionKey
                );

            $this->writeAll(
                $output,
                self::FILE_MAGIC
            );

            $this->writeAll(
                $output,
                $header
            );

            $currentChunk = fread(
                $input,
                self::CHUNK_SIZE
            );

            if ($currentChunk === false) {
                throw new RuntimeException(
                    'Failed to read the source file.'
                );
            }

            while ($currentChunk !== '') {
                $nextChunk = fread(
                    $input,
                    self::CHUNK_SIZE
                );

                if ($nextChunk === false) {
                    throw new RuntimeException(
                        'Failed while reading the source file.'
                    );
                }

                $tag = $nextChunk === ''
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $encryptedChunk =
                    sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $currentChunk,
                        '',
                        $tag
                    );

                $this->writeAll(
                    $output,
                    pack(
                        'N',
                        strlen($encryptedChunk)
                    )
                );

                $this->writeAll(
                    $output,
                    $encryptedChunk
                );

                $currentChunk = $nextChunk;
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /**
     * Decrypt a stored encrypted file.
     *
     * @throws RuntimeException
     */
    private function decryptFile(
        string $sourcePath,
        string $destinationPath
    ): void {
        $input = fopen(
            $sourcePath,
            'rb'
        );

        if ($input === false) {
            throw new RuntimeException(
                'Failed to open the encrypted source file.'
            );
        }

        $output = fopen(
            $destinationPath,
            'wb'
        );

        if ($output === false) {
            fclose($input);

            throw new RuntimeException(
                'Failed to open the decryption destination.'
            );
        }

        try {
            $magic = $this->readExact(
                $input,
                strlen(self::FILE_MAGIC)
            );

            if (! hash_equals(self::FILE_MAGIC, $magic)) {
                throw new RuntimeException(
                    'Invalid secure file format.'
                );
            }

            $header = $this->readExact(
                $input,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            );

            $state =
                sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                    $header,
                    $this->encryptionKey
                );

            $finalChunkSeen = false;

            while (! feof($input)) {
                $lengthBytes = fread(
                    $input,
                    4
                );

                if ($lengthBytes === false) {
                    throw new RuntimeException(
                        'Failed to read the encrypted frame length.'
                    );
                }

                if ($lengthBytes === '') {
                    break;
                }

                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException(
                        'The encrypted file frame is corrupted.'
                    );
                }

                $length = unpack(
                    'Nlength',
                    $lengthBytes
                );

                $frameLength = $length['length'] ?? 0;

                if ($frameLength <= 0) {
                    throw new RuntimeException(
                        'Invalid encrypted frame length.'
                    );
                }

                $encryptedChunk = $this->readExact(
                    $input,
                    $frameLength
                );

                $result =
                    sodium_crypto_secretstream_xchacha20poly1305_pull(
                        $state,
                        $encryptedChunk
                    );

                if ($result === false) {
                    throw new RuntimeException(
                        'Stored file authentication failed.'
                    );
                }

                [$plainChunk, $tag] = $result;

                $this->writeAll(
                    $output,
                    $plainChunk
                );

                if (
                    $tag
                    ===
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                ) {
                    $finalChunkSeen = true;

                    break;
                }
            }

            if (! $finalChunkSeen) {
                throw new RuntimeException(
                    'The encrypted file is incomplete.'
                );
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /**
     * Build a safe private storage path.
     *
     * @throws InvalidArgumentException
     */
    private function path(
        string $storageId
    ): string {
        $storageId = strtolower(
            trim($storageId)
        );

        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $storageId
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid secure storage identifier.'
            );
        }

        return $this->directory
            . DIRECTORY_SEPARATOR
            . $storageId
            . '.secure';
    }

    /**
     * Derive a fixed-length binary encryption key.
     */
    private function deriveKey(
        string $configuredKey
    ): string {
        $configuredKey = trim(
            $configuredKey
        );

        if (str_starts_with($configuredKey, 'base64:')) {
            $decoded = base64_decode(
                substr($configuredKey, 7),
                true
            );

            if ($decoded === false) {
                throw new RuntimeException(
                    'The configured encryption key is invalid.'
                );
            }

            $configuredKey = $decoded;
        }

        return sodium_crypto_generichash(
            $configuredKey,
            'ShuleOS.FileStorage.v1',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
        );
    }

    /**
     * Read an exact number of bytes.
     *
     * @param resource $stream
     *
     * @throws RuntimeException
     */
    private function readExact(
        $stream,
        int $length
    ): string {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread(
                $stream,
                $length - strlen($data)
            );

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(
                    'Unexpected end of encrypted file.'
                );
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Write all supplied bytes.
     *
     * @param resource $stream
     *
     * @throws RuntimeException
     */
    private function writeAll(
        $stream,
        string $data
    ): void {
        $written = 0;
        $length = strlen($data);

        while ($written < $length) {
            $result = fwrite(
                $stream,
                substr($data, $written)
            );

            if ($result === false || $result === 0) {
                throw new RuntimeException(
                    'Failed to write secure file data.'
                );
            }

            $written += $result;
        }
    }

    /**
     * Ensure the private storage directory exists.
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
                'Failed to create the secure storage directory.'
            );
        }

        if (! is_writable($this->directory)) {
            throw new RuntimeException(
                'The secure storage directory is not writable.'
            );
        }
    }

    /**
     * Delete a path without replacing the original exception.
     */
    private function deletePathSilently(
        string $path
    ): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Ensure a source file exists and is readable.
     *
     * @throws RuntimeException
     */
    private function assertReadableFile(
        string $path
    ): void {
        if (! is_file($path)) {
            throw new RuntimeException(
                sprintf(
                    'Source file does not exist [%s].',
                    $path
                )
            );
        }

        if (! is_readable($path)) {
            throw new RuntimeException(
                sprintf(
                    'Source file is not readable [%s].',
                    $path
                )
            );
        }
    }
}
