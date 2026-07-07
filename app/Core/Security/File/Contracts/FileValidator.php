<?php

declare(strict_types=1);

namespace App\Core\Security\File\Contracts;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Contract for all file security validators.
 *
 * Every validator within the ShuleOS Security
 * Framework must implement this interface.
 *
 * Examples:
 *
 * - ExtensionValidator
 * - MimeValidator
 * - MagicNumberValidator
 * - VirusScanner
 * - OfficeDocumentValidator
 * - ArchiveValidator
 */
interface FileValidator
{
       /**
     * Validate an uploaded file.
     *
     * Adds any detected issues to the supplied
     * FileSecurityReport.
     */
    public function validate(

        UploadedFile $file,

        FilePolicy $policy,

        FileSecurityReport $report

    ): void;

    /**
     * Validator name.
     */
    public function name(): SecurityValidator;

    /**
     * Whether this validator supports
     * the supplied policy.
     */
    public function supports(

        FilePolicy $policy

    ): bool;

    /**
     * Validator execution priority.
     *
     * Lower numbers execute first.
     */
    public function priority(): int;
}
