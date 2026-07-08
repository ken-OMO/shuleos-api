<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use InvalidArgumentException;

/**
 * Immutable File Security Policy.
 *
 * Defines all security rules governing
 * a specific upload workflow.
 *
 * Examples:
 * - Teacher Import
 * - Learner Import
 * - Finance Documents
 * - Library Upload
 * - Report Cards
 * - Staff Documents
 *
 * This object is immutable and therefore
 * thread-safe and reusable.
 */
final readonly class FilePolicy
{
    /**
     * Create a new immutable security policy.
     *
     * @param list<string> $allowedExtensions
     * @param list<string> $allowedMimeTypes
     *
     * @throws InvalidArgumentException
     */
    public function __construct(

        /*
        |--------------------------------------------------------------------------
        | Policy
        |--------------------------------------------------------------------------
        */

        public string $policyName = 'Default',

        /*
        |--------------------------------------------------------------------------
        | Allowed File Types
        |--------------------------------------------------------------------------
        */

        public array $allowedExtensions,

        public array $allowedMimeTypes,

        /*
        |--------------------------------------------------------------------------
        | File Limits
        |--------------------------------------------------------------------------
        */

        public int $maximumFileSize,

        public ?int $maximumImportRows = null,

        public ?int $maximumImportColumns = null,

        /*
        |--------------------------------------------------------------------------
        | Core Security
        |--------------------------------------------------------------------------
        */

        public bool $requireVirusScan = true,

        public bool $requireMagicNumberValidation = true,

        public bool $requireMimeValidation = true,

        public bool $requireHashing = true,

        public bool $requireQuarantine = true,

        public bool $encryptAfterUpload = true,

        public bool $deleteAfterProcessing = true,

        public bool $auditUploads = true,

        /*
        |--------------------------------------------------------------------------
        | Advanced Security
        |--------------------------------------------------------------------------
        */

        public bool $scanArchives = true,

        public bool $scanForViruses = true,

        public bool $requireMalwareSandbox = false,

        public bool $requireDigitalSignature = false,

        public bool $requireSchoolOwnershipValidation = true,

        /*
        |--------------------------------------------------------------------------
        | Office Documents
        |--------------------------------------------------------------------------
        */

        public bool $allowMacros = false,

        public bool $allowPasswordProtectedFiles = false,

        /*
        |--------------------------------------------------------------------------
        | Pipeline Behaviour
        |--------------------------------------------------------------------------
        */

        public bool $failFast = false,

        /*
        |--------------------------------------------------------------------------
        | Upload Behaviour
        |--------------------------------------------------------------------------
        */

        public bool $allowDuplicateFiles = false,

        public bool $allowOverwrite = false,

        public bool $keepOriginalFilename = false,

        public bool $generateThumbnail = false

    ) {

        $this->validate();

    }
        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the policy.
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        $this->validateLimits();

        $this->validateExtensions();

        $this->validateMimeTypes();

        $this->validateConfiguration();
    }

    /**
     * Validate file limits.
     */
    private function validateLimits(): void
    {
        if ($this->maximumFileSize <= 0) {

            throw new InvalidArgumentException(
                'Maximum file size must be greater than zero.'
            );

        }

        if (

            $this->maximumImportRows !== null
            &&
            $this->maximumImportRows <= 0

        ) {

            throw new InvalidArgumentException(
                'Maximum import rows must be greater than zero.'
            );

        }

        if (

            $this->maximumImportColumns !== null
            &&
            $this->maximumImportColumns <= 0

        ) {

            throw new InvalidArgumentException(
                'Maximum import columns must be greater than zero.'
            );

        }
    }

    /**
     * Validate allowed file extensions.
     */
    private function validateExtensions(): void
    {
        if (empty($this->allowedExtensions)) {

            throw new InvalidArgumentException(
                'At least one allowed file extension is required.'
            );

        }

        $extensions = array_map(

            'strtolower',

            $this->allowedExtensions

        );

        if (

            count($extensions)
            !==
            count(array_unique($extensions))

        ) {

            throw new InvalidArgumentException(
                'Duplicate file extensions are not allowed.'
            );

        }

        foreach ($extensions as $extension) {

            if (

                ! preg_match(

                    '/^[a-z0-9]+$/',

                    $extension

                )

            ) {

                throw new InvalidArgumentException(

                    "Invalid file extension [{$extension}]."

                );

            }

        }

    }

    /**
     * Validate allowed MIME types.
     */
    private function validateMimeTypes(): void
    {
        if (empty($this->allowedMimeTypes)) {

            throw new InvalidArgumentException(
                'At least one allowed MIME type is required.'
            );

        }

        $mimeTypes = array_map(

            'strtolower',

            $this->allowedMimeTypes

        );

        if (

            count($mimeTypes)
            !==
            count(array_unique($mimeTypes))

        ) {

            throw new InvalidArgumentException(
                'Duplicate MIME types are not allowed.'
            );

        }

        foreach ($mimeTypes as $mimeType) {

            if (

                ! str_contains(

                    $mimeType,

                    '/'

                )

            ) {

                throw new InvalidArgumentException(

                    "Invalid MIME type [{$mimeType}]."

                );

            }

        }

    }

    /**
     * Validate security configuration.
     */
    private function validateConfiguration(): void
    {
        if (

            $this->allowMacros
            &&
            ! $this->requireVirusScan

        ) {

            throw new InvalidArgumentException(

                'Macro-enabled documents require virus scanning.'

            );

        }

        if (

            $this->requireMalwareSandbox
            &&
            ! $this->requireVirusScan

        ) {

            throw new InvalidArgumentException(

                'Malware sandbox requires virus scanning.'

            );

        }

        if (

            $this->allowOverwrite
            &&
            ! $this->auditUploads

        ) {

            throw new InvalidArgumentException(

                'Overwrite operations require audit logging.'

            );

        }

        if (

            $this->requireDigitalSignature
            &&
            ! $this->auditUploads

        ) {

            throw new InvalidArgumentException(

                'Digital signature validation requires audit logging.'

            );

        }

        if (

            $this->encryptAfterUpload
            &&
            ! $this->requireHashing

        ) {

            throw new InvalidArgumentException(

                'Encryption requires file hashing.'

            );

        }

    }
        /*
    |--------------------------------------------------------------------------
    | Policy Decisions
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether the given file extension is allowed.
     */
    public function allowsExtension(
        string $extension
    ): bool {

        return in_array(

            strtolower($extension),

            array_map(

                'strtolower',

                $this->allowedExtensions

            ),

            true

        );

    }

    /**
     * Determine whether the given MIME type is allowed.
     */
    public function allowsMimeType(
        string $mimeType
    ): bool {

        return in_array(

            strtolower($mimeType),

            array_map(

                'strtolower',

                $this->allowedMimeTypes

            ),

            true

        );

    }

    /**
     * Determine whether the file size is allowed.
     */
    public function allowsFileSize(
        int $bytes
    ): bool {

        return $bytes >= 0
            &&
            $bytes <= $this->maximumFileSize;

    }

    /**
     * Determine whether the imported row count is allowed.
     */
    public function allowsRows(
        int $rows
    ): bool {

        return

            $this->maximumImportRows === null

            ||

            $rows <= $this->maximumImportRows;

    }

    /**
     * Determine whether the imported column count is allowed.
     */
    public function allowsColumns(
        int $columns
    ): bool {

        return

            $this->maximumImportColumns === null

            ||

            $columns <= $this->maximumImportColumns;

    }

    /**
     * Determine whether duplicate uploads are permitted.
     */
    public function allowsDuplicateFiles(): bool
    {
        return $this->allowDuplicateFiles;
    }

    /**
     * Determine whether existing files may be overwritten.
     */
    public function allowsOverwrite(): bool
    {
        return $this->allowOverwrite;
    }

    /**
     * Determine whether Office macros are permitted.
     */
    public function allowsMacros(): bool
    {
        return $this->allowMacros;
    }

    /**
     * Determine whether password-protected files are permitted.
     */
    public function allowsPasswordProtectedFiles(): bool
    {
        return $this->allowPasswordProtectedFiles;
    }
        /*
    |--------------------------------------------------------------------------
    | Security Requirements
    |--------------------------------------------------------------------------
    */

    /**
     * Whether virus scanning is required.
     */
    public function requiresVirusScan(): bool
    {
        return $this->requireVirusScan;
    }

    /**
     * Whether MIME validation is required.
     */
    public function requiresMimeValidation(): bool
    {
        return $this->requireMimeValidation;
    }

    /**
     * Whether magic number validation is required.
     */
    public function requiresMagicNumberValidation(): bool
    {
        return $this->requireMagicNumberValidation;
    }

    /**
     * Whether file hashing is required.
     */
    public function requiresHashing(): bool
    {
        return $this->requireHashing;
    }

    /**
     * Whether quarantine is required.
     */
    public function requiresQuarantine(): bool
    {
        return $this->requireQuarantine;
    }

    /**
     * Whether uploaded files should be encrypted.
     */
    public function requiresEncryption(): bool
    {
        return $this->encryptAfterUpload;
    }

    /**
     * Whether temporary files should be deleted after processing.
     */
    public function requiresDeletionAfterProcessing(): bool
    {
        return $this->deleteAfterProcessing;
    }

    /**
     * Whether uploads should be audited.
     */
    public function requiresAudit(): bool
    {
        return $this->auditUploads;
    }

    /**
     * Whether archive files should be scanned.
     */
    public function requiresArchiveScanning(): bool
    {
        return $this->scanArchives;
    }

    public function scansForViruses(): bool
{
    return $this->scanForViruses;
}

    /**
     * Whether malware sandbox analysis is required.
     */
    public function requiresMalwareSandbox(): bool
    {
        return $this->requireMalwareSandbox;
    }

    /**
     * Whether digital signatures are required.
     */
    public function requiresDigitalSignature(): bool
    {
        return $this->requireDigitalSignature;
    }

    /**
     * Whether school ownership validation is required.
     */
    public function requiresSchoolOwnershipValidation(): bool
    {
        return $this->requireSchoolOwnershipValidation;
    }

    /**
     * Whether validation should stop after the first failure.
     */
    public function failsFast(): bool
    {
        return $this->failFast;
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Behaviour
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the original filename should be preserved.
     */
    public function keepsOriginalFilename(): bool
    {
        return $this->keepOriginalFilename;
    }

    /**
     * Whether a thumbnail should be generated.
     */
    public function generatesThumbnail(): bool
    {
        return $this->generateThumbnail;
    }
        /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    /**
     * Export the policy as an array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [

            'policy_name' => $this->policyName,

            'allowed_extensions' => $this->allowedExtensions,

            'allowed_mime_types' => $this->allowedMimeTypes,

            'maximum_file_size' => $this->maximumFileSize,

            'maximum_import_rows' => $this->maximumImportRows,

            'maximum_import_columns' => $this->maximumImportColumns,

            'require_virus_scan' => $this->requireVirusScan,

            'require_magic_number_validation' =>
                $this->requireMagicNumberValidation,

            'require_mime_validation' =>
                $this->requireMimeValidation,

            'require_hashing' =>
                $this->requireHashing,

            'require_quarantine' =>
                $this->requireQuarantine,

            'encrypt_after_upload' =>
                $this->encryptAfterUpload,

            'delete_after_processing' =>
                $this->deleteAfterProcessing,

            'audit_uploads' =>
                $this->auditUploads,

            'scan_archives' =>
                $this->scanArchives,

                $this->scanForViruses,

            'require_malware_sandbox' =>
                $this->requireMalwareSandbox,

            'require_digital_signature' =>
                $this->requireDigitalSignature,

            'require_school_ownership_validation' =>
                $this->requireSchoolOwnershipValidation,

            'allow_macros' =>
                $this->allowMacros,

            'allow_password_protected_files' =>
                $this->allowPasswordProtectedFiles,

            'fail_fast' =>
                $this->failFast,

            'allow_duplicate_files' =>
                $this->allowDuplicateFiles,

            'allow_overwrite' =>
                $this->allowOverwrite,

            'keep_original_filename' =>
                $this->keepOriginalFilename,

            'generate_thumbnail' =>
                $this->generateThumbnail,

        ];
    }

    /**
     * Human-readable summary.
     */
    public function summary(): string
    {
        return sprintf(

            '%s | %d extension(s) | %d MIME type(s) | Max: %s MB',

            $this->policyName,

            count($this->allowedExtensions),

            count($this->allowedMimeTypes),

            number_format(

                $this->maximumFileSize / 1024 / 1024,

                2

            )

        );
    }

    /**
     * Convert policy to JSON.
     *
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode(

            $this->toArray(),

            JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR

        );
    }
}
