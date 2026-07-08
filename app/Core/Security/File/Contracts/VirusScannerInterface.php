<?php

declare(strict_types=1);

namespace App\Core\Security\File\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Contract for malware scanners.
 *
 * Every antivirus engine integrated into
 * the ShuleOS Security Framework must
 * implement this interface.
 *
 * Examples:
 *
 * - ClamAVScanner
 * - NullVirusScanner
 * - CloudVirusScanner
 */
interface VirusScannerInterface
{
    /**
     * Scan an uploaded file.
     *
     * Returns true when the file is clean.
     */
    public function scan(
        UploadedFile $file
    ): bool;

    /**
     * Determine whether the scanner
     * is available.
     */
    public function available(): bool;

    /**
     * Return the scanner name.
     */
    public function name(): string;

    /**
     * Return the malware signature
     * detected during the last scan.
     *
     * Returns null when the file is clean.
     */
    public function detectedThreat(): ?string;
}
