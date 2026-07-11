<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\MagicNumberDatabase;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Validates uploaded files using binary signatures.
 *
 * Compares the file's actual contents against
 * the registered signatures for its extension.
 */
final class MagicNumberValidator extends AbstractFileValidator
{
    /**
     * Maximum number of header bytes to read.
     */
    private const HEADER_BYTES = 64;

    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::MAGIC_NUMBER;
    }

    /**
     * Validate the uploaded file's binary signature.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {
        $extension = strtolower(
            trim($file->getClientOriginalExtension())
        );

        /*
        |--------------------------------------------------------------------------
        | Unsupported Signature Type
        |--------------------------------------------------------------------------
        */

        if (! MagicNumberDatabase::supports($extension)) {
            return;
        }

        $signatures = MagicNumberDatabase::forExtension(
            $extension
        );

        /*
        |--------------------------------------------------------------------------
        | Text Formats Without Fixed Magic Numbers
        |--------------------------------------------------------------------------
        */

        if ($signatures === []) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Read File Header
        |--------------------------------------------------------------------------
        */

        try {
            $header = $this->readHeader($file);
        } catch (RuntimeException $exception) {
            $this->fail(
                report: $report,
                code: SecurityIssueCode::FILE_CORRUPTED,
                context: [
                    'extension' => $extension,
                    'file_name' => $file->getClientOriginalName(),
                    'reason' => $exception->getMessage(),
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Compare Known Signatures
        |--------------------------------------------------------------------------
        */

        foreach ($signatures as $magicNumber) {
            if ($magicNumber->matches($header)) {
                $this->pass($report);

                return;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Invalid Signature
        |--------------------------------------------------------------------------
        */

        $this->fail(
            report: $report,
            code: SecurityIssueCode::INVALID_MAGIC_NUMBER,
            context: [
                'extension' => $extension,
                'file_name' => $file->getClientOriginalName(),
                'detected_header' => $header,
                'expected_signatures' => array_map(
                    static fn ($magicNumber): string =>
                        $magicNumber->signature(),
                    $signatures
                ),
            ]
        );
    }

    /**
     * Determine whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {
        return $policy->requiresMagicNumberValidation();
    }

    /**
     * Read the file header and convert it to hexadecimal.
     *
     * @throws RuntimeException
     */
    private function readHeader(
        UploadedFile $file
    ): string {
        $path = $file->getRealPath();

        if (
            $path === false
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            throw new RuntimeException(
                'The uploaded file cannot be read.'
            );
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                'Failed to open the uploaded file.'
            );
        }

        try {
            $data = fread(
                $handle,
                self::HEADER_BYTES
            );

            if ($data === false) {
                throw new RuntimeException(
                    'Failed to read the uploaded file header.'
                );
            }

            if ($data === '') {
                throw new RuntimeException(
                    'The uploaded file is empty.'
                );
            }

            return strtoupper(
                bin2hex($data)
            );
        } finally {
            fclose($handle);
        }
    }
}
