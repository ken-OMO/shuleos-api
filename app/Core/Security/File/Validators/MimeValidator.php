<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\FileValidator;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssue;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded file MIME types.
 *
 * Ensures that the uploaded file MIME type
 * is permitted by the active FilePolicy.
 */
final class MimeValidator implements FileValidator
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

            (string) $file->getMimeType()

        );

        if (

            $policy->allowsMimeType($mimeType)

        ) {

            return;

        }

        $report->addIssue(

            new SecurityIssue(

                code: SecurityIssueCode::INVALID_MIME_TYPE,

                context: [

                    'mime_type' => $mimeType,

                    'allowed_mime_types' => $policy->allowedMimeTypes,

                    'file_name' => $file->getClientOriginalName(),

                ]

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

        return true;

    }

    /**
     * Validator execution priority.
     *
     * Lower numbers execute first.
     */
    public function priority(): int
    {
        return 20;
    }
}
