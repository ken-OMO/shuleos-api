<?php

declare(strict_types=1);

namespace App\Core\Security\File\Scanners;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use Illuminate\Http\UploadedFile;

/**
 * Null malware scanner.
 *
 * Development implementation that always
 * reports uploaded files as clean.
 *
 * Useful when no antivirus engine is
 * installed on the current machine.
 */
final class NullVirusScanner implements VirusScannerInterface
{
    /**
     * Last detected threat.
     */
    private ?string $threat = null;

    /**
     * Scan an uploaded file.
     *
     * Always returns true.
     */
    public function scan(
        UploadedFile $file
    ): bool {

        $this->threat = null;

        return true;

    }

    /**
     * Scanner availability.
     */
    public function available(): bool
    {
        return true;
    }

    /**
     * Scanner name.
     */
    public function name(): string
    {
        return 'Null Virus Scanner';
    }

    /**
     * Last detected threat.
     */
    public function detectedThreat(): ?string
    {
        return $this->threat;
    }
}
