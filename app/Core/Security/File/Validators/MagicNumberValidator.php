<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\FileValidator;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\MagicNumberDatabase;
use App\Core\Security\File\SecurityIssue;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded files using their
 * binary magic numbers.
 *
 * This validator compares the actual
 * binary signature of a file against the
 * official signature database.
 */
final class MagicNumberValidator implements FileValidator
{
    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::MAGIC_NUMBER;
    }
        /**
     * Validate file signature.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {

        if (

            !$policy->requireMagicNumberValidation

        ) {

            return;

        }

        $extension = strtolower(

            $file->getClientOriginalExtension()

        );

        if (

            !MagicNumberDatabase::supports($extension)

        ) {

            return;

        }

        $header = $this->readHeader(

            $file

        );

        foreach (

            MagicNumberDatabase::forExtension($extension)

            as $magic

        ) {

            if (

                $magic->matches($header)

            ) {

                return;

            }

        }

        $report->addIssue(

            new SecurityIssue(

                SecurityIssueCode::INVALID_MAGIC_NUMBER,

                [

                    'extension' => $extension,

                    'file_name' => $file->getClientOriginalName(),

                ]

            )

        );

    }
        /**
     * Read the first bytes of the file
     * and convert them to hexadecimal.
     */
    private function readHeader(
        UploadedFile $file,
        int $bytes = 32
    ): string {

        $handle = fopen(

            $file->getRealPath(),

            'rb'

        );

        if (

            $handle === false

        ) {

            return '';

        }

        $data = fread(

            $handle,

            $bytes

        );

        fclose(

            $handle

        );

        return strtoupper(

            bin2hex(

                $data ?: ''

            )

        );

    }
        /**
     * Whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {

        return $policy->requireMagicNumberValidation;

    }

    /**
     * Validator priority.
     */
    public function priority(): int
    {
        return 30;
    }
}
