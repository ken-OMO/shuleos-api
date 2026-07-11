<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded file extensions.
 */
final class ExtensionValidator extends AbstractFileValidator
{
    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::EXTENSION;
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
            trim($file->getClientOriginalExtension())
        );

        if ($policy->allowsExtension($extension)) {
            $this->pass($report);

            return;
        }

        $this->fail(
            report: $report,
            code: SecurityIssueCode::INVALID_EXTENSION,
            context: [
                'extension' => $extension,
                'allowed_extensions' => $policy->allowedExtensions,
                'file_name' => $file->getClientOriginalName(),
            ]
        );
    }
}
