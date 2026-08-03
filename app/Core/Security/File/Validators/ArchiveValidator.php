<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Validates ZIP-based archive files.
 *
 * Detects:
 *
 * - Excessive archive entries
 * - Excessive extracted size
 * - Suspicious compression ratios
 * - Dangerous embedded file types
 * - Directory traversal entries
 * - Nested archive files
 */
final class ArchiveValidator extends AbstractFileValidator
{
    /**
     * Maximum allowed archive entries.
     */
    private const MAX_FILES = 1000;

    /**
     * Maximum total uncompressed size.
     */
    private const MAX_UNCOMPRESSED_SIZE = 500 * 1024 * 1024;

    /**
     * Maximum allowed compression ratio.
     */
    private const MAX_COMPRESSION_RATIO = 100.0;

    /**
     * ZIP-based extensions supported by this validator.
     *
     * @var list<string>
     */
    private const SUPPORTED_EXTENSIONS = [
        'zip',
        'docx',
        'xlsx',
        'pptx',
        'apk',
        'epub',
    ];

    /**
     * Dangerous embedded file extensions.
     *
     * @var list<string>
     */
    private const DANGEROUS_EXTENSIONS = [
        'exe',
        'dll',
        'bat',
        'cmd',
        'com',
        'cpl',
        'hta',
        'jar',
        'js',
        'jse',
        'lnk',
        'msi',
        'msp',
        'ps1',
        'psm1',
        'reg',
        'scr',
        'vb',
        'vbe',
        'vbs',
        'wsf',
        'wsh',
    ];

    /**
     * Nested archive extensions.
     *
     * @var list<string>
     */
    private const ARCHIVE_EXTENSIONS = [
        'zip',
        'rar',
        '7z',
        'gz',
        'gzip',
        'tar',
        'bz2',
        'xz',
    ];

    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::ARCHIVE;
    }

    /**
     * Validate the uploaded archive.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {
        $extension = strtolower(
            trim($file->getClientOriginalExtension())
        );

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return;
        }

        try {
            $this->inspectArchive($file);

            $this->pass($report);
        } catch (RuntimeException $exception) {
            $this->fail(
                report: $report,
                code: SecurityIssueCode::SUSPICIOUS_FILE,
                context: [
                    'extension' => $extension,
                    'file_name' => $file->getClientOriginalName(),
                    'reason' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * Determine whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {
        return $policy->requiresArchiveScanning();
    }

    /**
     * Inspect the archive contents.
     *
     * @throws RuntimeException
     */
    private function inspectArchive(
        UploadedFile $file
    ): void {
        $path = $file->getRealPath();

        if (
            $path === false
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            throw new RuntimeException(
                'The uploaded archive cannot be read.'
            );
        }

        $zip = new ZipArchive;

        $openResult = $zip->open($path);

        if ($openResult !== true) {
            throw new RuntimeException(
                'The uploaded file is not a valid ZIP archive.'
            );
        }

        try {
            $fileCount = 0;
            $totalCompressedSize = 0;
            $totalUncompressedSize = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Unable to inspect archive entry at index [%d].',
                            $index
                        )
                    );
                }

                $entryName = (string) ($stat['name'] ?? '');

                if ($entryName === '') {
                    throw new RuntimeException(
                        'The archive contains an unnamed entry.'
                    );
                }

                if ($this->isDirectoryEntry($entryName)) {
                    continue;
                }

                $fileCount++;

                if ($fileCount > self::MAX_FILES) {
                    throw new RuntimeException(
                        sprintf(
                            'The archive contains more than %d files.',
                            self::MAX_FILES
                        )
                    );
                }

                $compressedSize = max(
                    0,
                    (int) ($stat['comp_size'] ?? 0)
                );

                $uncompressedSize = max(
                    0,
                    (int) ($stat['size'] ?? 0)
                );

                $totalCompressedSize += $compressedSize;
                $totalUncompressedSize += $uncompressedSize;

                if (
                    $totalUncompressedSize
                    > self::MAX_UNCOMPRESSED_SIZE
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'The archive exceeds the maximum extracted size of %d bytes.',
                            self::MAX_UNCOMPRESSED_SIZE
                        )
                    );
                }

                $this->assertSafeEntryName($entryName);

                $entryExtension = strtolower(
                    pathinfo(
                        $entryName,
                        PATHINFO_EXTENSION
                    )
                );

                if (
                    in_array(
                        $entryExtension,
                        self::DANGEROUS_EXTENSIONS,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Dangerous archive entry detected [%s].',
                            $entryName
                        )
                    );
                }

                if (
                    in_array(
                        $entryExtension,
                        self::ARCHIVE_EXTENSIONS,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Nested archive detected [%s].',
                            $entryName
                        )
                    );
                }
            }

            if (
                $totalCompressedSize > 0
                && (
                    $totalUncompressedSize
                    / $totalCompressedSize
                ) > self::MAX_COMPRESSION_RATIO
            ) {
                throw new RuntimeException(
                    sprintf(
                        'The archive compression ratio exceeds the maximum allowed ratio of %.2f.',
                        self::MAX_COMPRESSION_RATIO
                    )
                );
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Determine whether an entry is a directory.
     */
    private function isDirectoryEntry(
        string $entryName
    ): bool {
        return str_ends_with(
            str_replace('\\', '/', $entryName),
            '/'
        );
    }

    /**
     * Reject unsafe archive entry names.
     *
     * @throws RuntimeException
     */
    private function assertSafeEntryName(
        string $entryName
    ): void {
        $normalized = str_replace(
            '\\',
            '/',
            $entryName
        );

        if (
            str_contains($normalized, '../')
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalized) === 1
            || str_contains($normalized, "\0")
        ) {
            throw new RuntimeException(
                sprintf(
                    'Unsafe archive path detected [%s].',
                    $entryName
                )
            );
        }
    }
}
