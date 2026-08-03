<?php

declare(strict_types=1);

namespace App\Core\Security\File\Scanners;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use Illuminate\Http\UploadedFile;

/**
 * ClamAV malware scanner.
 *
 * Uses the clamscan command-line utility
 * to scan uploaded files for malware.
 */
final class ClamAVScanner implements VirusScannerInterface
{
    /**
     * Last detected threat.
     */
    private ?string $threat = null;

    /**
     * Determine whether ClamAV
     * is available.
     */
    public function available(): bool
    {
        $command = strtoupper(

            substr(

                PHP_OS,

                0,

                3

            )

        ) === 'WIN'

            ? 'where clamscan'

            : 'which clamscan';

        exec(

            $command,

            $output,

            $status

        );

        return $status === 0;
    }

    /**
     * Scanner name.
     */
    public function name(): string
    {
        return 'ClamAV';
    }

    /**
     * Return the last detected threat.
     */
    public function detectedThreat(): ?string
    {
        return $this->threat;
    }

    /**
     * Scan an uploaded file.
     */
    public function scan(
        UploadedFile $file
    ): bool {

        $this->threat = null;

        if (

            ! $this->available()

        ) {

            return true;

        }

        $command = sprintf(

            'clamscan --no-summary %s',

            escapeshellarg(

                $file->getRealPath()

            )

        );

        exec(

            $command,

            $output,

            $status

        );

        /*
        |--------------------------------------------------------------------------
        | Exit Codes
        |--------------------------------------------------------------------------
        |
        | 0 = Clean
        | 1 = Virus Found
        | 2 = Error
        |
        */

        if (

            $status === 0

        ) {

            return true;

        }

        if (

            $status === 1

        ) {

            $this->threat = trim(

                implode(

                    PHP_EOL,

                    $output

                )

            );

            return false;

        }

        /*
        |--------------------------------------------------------------------------
        | Scanner Error
        |--------------------------------------------------------------------------
        */

        $this->threat = 'Scanner execution failed.';

        return false;

    }
}
