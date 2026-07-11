<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Validates Microsoft Office Open XML documents.
 *
 * Ensures that DOCX, XLSX, and PPTX files
 * contain the correct internal structure.
 */
final class OfficeDocumentValidator extends AbstractFileValidator
{
    /**
     * Supported Office Open XML extensions.
     *
     * @var list<string>
     */
    private const SUPPORTED_EXTENSIONS = [
        'docx',
        'xlsx',
        'pptx',
    ];

    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::OFFICE_DOCUMENT;
    }

    /**
     * Validate the Office document structure.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {
        $extension = strtolower(
            trim($file->getClientOriginalExtension())
        );

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return;
        }

        try {
            $this->assertValidStructure(
                file: $file,
                extension: $extension
            );

            $this->pass($report);
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
        }
    }

    /**
     * Determine whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {
        foreach (self::SUPPORTED_EXTENSIONS as $extension) {
            if ($policy->allowsExtension($extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify the internal Office document structure.
     *
     * @throws RuntimeException
     */
    private function assertValidStructure(
        UploadedFile $file,
        string $extension
    ): void {
        $path = $file->getRealPath();

        if (
            $path === false
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            throw new RuntimeException(
                'The uploaded Office document cannot be read.'
            );
        }

        $zip = new ZipArchive;

        $openResult = $zip->open($path);

        if ($openResult !== true) {
            throw new RuntimeException(
                'The uploaded Office document is not a valid ZIP container.'
            );
        }

        try {
            foreach (
                $this->requiredEntries($extension) as $requiredEntry
            ) {
                if ($zip->locateName($requiredEntry) === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Required Office document entry [%s] is missing.',
                            $requiredEntry
                        )
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Return the required internal entries.
     *
     * @return list<string>
     */
    private function requiredEntries(
        string $extension
    ): array {
        return match ($extension) {
            'docx' => [
                '[Content_Types].xml',
                '_rels/.rels',
                'word/document.xml',
            ],

            'xlsx' => [
                '[Content_Types].xml',
                '_rels/.rels',
                'xl/workbook.xml',
            ],

            'pptx' => [
                '[Content_Types].xml',
                '_rels/.rels',
                'ppt/presentation.xml',
            ],

            default => throw new RuntimeException(
                sprintf(
                    'Unsupported Office document extension [%s].',
                    $extension
                )
            ),
        };
    }
}
