<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded file MIME types.
 */
final class MimeValidator extends AbstractFileValidator
{
    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::MIME;
    }

    /**
     * Validate the uploaded file MIME type.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {
        $mimeType = strtolower(
            trim((string) $file->getMimeType())
        );

        if ($policy->allowsMimeType($mimeType)) {
            $this->pass($report);

            return;
        }

        $this->fail(
            report: $report,
            code: SecurityIssueCode::INVALID_MIME_TYPE,
            context: [
                'mime_type' => $mimeType,
                'allowed_mime_types' => $policy->allowedMimeTypes,
                'file_name' => $file->getClientOriginalName(),
            ]
        );
    }
}
