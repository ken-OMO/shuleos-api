<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Categories of security issues detected by the
 * ShuleOS Enterprise Security Framework.
 */
enum SecurityIssueCategory: string
{
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    case VALIDATION = 'validation';

    /*
    |--------------------------------------------------------------------------
    | Malware
    |--------------------------------------------------------------------------
    */

    case MALWARE = 'malware';

    /*
    |--------------------------------------------------------------------------
    | Office Documents
    |--------------------------------------------------------------------------
    */

    case OFFICE_DOCUMENT = 'office_document';

    /*
    |--------------------------------------------------------------------------
    | File Integrity
    |--------------------------------------------------------------------------
    */

    case FILE_INTEGRITY = 'file_integrity';

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    case AUTHORIZATION = 'authorization';

    /*
    |--------------------------------------------------------------------------
    | Secure Storage
    |--------------------------------------------------------------------------
    */

    case STORAGE = 'storage';

    /*
    |--------------------------------------------------------------------------
    | Import Validation
    |--------------------------------------------------------------------------
    */

    case IMPORT = 'import';

    /*
    |--------------------------------------------------------------------------
    | Unknown
    |--------------------------------------------------------------------------
    */

    case UNKNOWN = 'unknown';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {

            self::VALIDATION => 'Validation',

            self::MALWARE => 'Malware',

            self::OFFICE_DOCUMENT => 'Office Document',

            self::FILE_INTEGRITY => 'File Integrity',

            self::AUTHORIZATION => 'Authorization',

            self::STORAGE => 'Secure Storage',

            self::IMPORT => 'Import',

            self::UNKNOWN => 'Unknown',

        };
    }

    /**
     * Detailed description.
     */
    public function description(): string
    {
        return match ($this) {

            self::VALIDATION =>
                'File format and structural validation.',

            self::MALWARE =>
                'Malicious software detection.',

            self::OFFICE_DOCUMENT =>
                'Office document security analysis.',

            self::FILE_INTEGRITY =>
                'Verification of file authenticity and integrity.',

            self::AUTHORIZATION =>
                'Authentication and authorization checks.',

            self::STORAGE =>
                'Secure file storage and quarantine.',

            self::IMPORT =>
                'Import structure and content validation.',

            self::UNKNOWN =>
                'Unclassified security issue.',

        };
    }

    /**
     * Priority for reporting.
     *
     * Lower numbers appear first.
     */
    public function priority(): int
    {
        return match ($this) {

            self::VALIDATION => 10,

            self::MALWARE => 20,

            self::OFFICE_DOCUMENT => 30,

            self::FILE_INTEGRITY => 40,

            self::AUTHORIZATION => 50,

            self::STORAGE => 60,

            self::IMPORT => 70,

            self::UNKNOWN => 999,

        };
    }

    /**
     * Dashboard color.
     */
    public function color(): string
    {
        return match ($this) {

            self::VALIDATION => 'blue',

            self::MALWARE => 'red',

            self::OFFICE_DOCUMENT => 'orange',

            self::FILE_INTEGRITY => 'purple',

            self::AUTHORIZATION => 'yellow',

            self::STORAGE => 'green',

            self::IMPORT => 'cyan',

            self::UNKNOWN => 'gray',

        };
    }

    /**
     * Dashboard icon.
     */
    public function icon(): string
    {
        return match ($this) {

            self::VALIDATION => 'shield-check',

            self::MALWARE => 'virus',

            self::OFFICE_DOCUMENT => 'file-text',

            self::FILE_INTEGRITY => 'fingerprint',

            self::AUTHORIZATION => 'lock',

            self::STORAGE => 'database',

            self::IMPORT => 'upload',

            self::UNKNOWN => 'help-circle',

        };
    }

    /**
     * Whether the category represents a critical
     * security domain.
     */
    public function isCritical(): bool
    {
        return match ($this) {

            self::MALWARE,
            self::FILE_INTEGRITY,
            self::AUTHORIZATION => true,

            default => false,

        };
    }
}
