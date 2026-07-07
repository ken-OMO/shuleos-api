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
use ZipArchive;

/**
 * Validates Microsoft Office documents.
 *
 * Ensures that DOCX, XLSX and PPTX files
 * contain the correct internal structure.
 */
final class OfficeDocumentValidator implements FileValidator
{
    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::OFFICE_DOCUMENT;
    }
        /**
     * Validate Office document structure.
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

            !in_array(

                $extension,

                [

                    'docx',

                    'xlsx',

                    'pptx',

                ],

                true

            )

        ) {

            return;

        }

        if (

            !$this->validateStructure(

                $file,

                $extension

            )

        ) {

            $report->addIssue(

                new SecurityIssue(

                    SecurityIssueCode::FILE_CORRUPTED,

                    [

                        'extension' => $extension,

                        'file_name' => $file->getClientOriginalName(),

                    ]

                )

            );

        }

    }
        /**
     * Validate the internal structure
     * of an Office document.
     */
    private function validateStructure(
        UploadedFile $file,
        string $extension
    ): bool {

        $zip = new ZipArchive();

        if (

            $zip->open(

                $file->getRealPath()

            ) !== true

        ) {

            return false;

        }

        $requiredDirectory = match ($extension) {

            'docx' => 'word/',

            'xlsx' => 'xl/',

            'pptx' => 'ppt/',

            default => null,

        };

        if (

            $requiredDirectory === null

        ) {

            $zip->close();

            return true;

        }

        $found = false;

        for (

            $index = 0;

            $index < $zip->numFiles;

            $index++

        ) {

            $name = $zip->getNameIndex(

                $index

            );

            if (

                $name !== false &&

                str_starts_with(

                    $name,

                    $requiredDirectory

                )

            ) {

                $found = true;

                break;

            }

        }

        $zip->close();

        return $found;

    }
        /**
     * Determine whether this validator
     * supports the supplied policy.
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
        return 40;
    }
}
