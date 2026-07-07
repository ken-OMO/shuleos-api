<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\FileValidator;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityCatalog;
use App\Core\Security\File\SecurityIssue;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded file extensions.
 *
 * Ensures the uploaded file extension
 * is permitted by the active FilePolicy.
 */
final class ExtensionValidator implements FileValidator
{
    /**
     * Validator name.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::EXTENSION;
    }

    /**
     * Execution priority.
     */
    public function priority(): int
    {
        return 10;
    }

    /**
     * Whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {

        return true;

    }
        /**
     * Validate the uploaded file extension.
     */
    public function validate(

        UploadedFile $file,

        FilePolicy $policy,

        FileSecurityReport $report

    ): void {

        $extension = strtolower(

            $file->getClientOriginalExtension()

        );

        if (

            $policy->allowsExtension($extension)

        ) {

            return;

        }

        $report->addIssue(

    new SecurityIssue(

        SecurityIssueCode::INVALID_EXTENSION,

        [

            'extension' => $extension,

            'allowed_extensions' => $policy->allowedExtensions,

            'file_name' => $file->getClientOriginalName(),

        ]

    )

);

    }
}
