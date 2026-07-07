<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * File security validators.
 *
 * These validators form the official
 * ShuleOS File Security Pipeline.
 */
enum SecurityValidator: string
{
    /*
    |--------------------------------------------------------------------------
    | File Validation
    |--------------------------------------------------------------------------
    */

    case EXTENSION = 'ExtensionValidator';

    case MIME = 'MimeValidator';

    case MAGIC_NUMBER = 'MagicNumberValidator';

    /*
|--------------------------------------------------------------------------
| Office Documents
|--------------------------------------------------------------------------
*/

case OFFICE_DOCUMENT = 'OfficeDocumentValidator';

/*
|--------------------------------------------------------------------------
| Archive Validation
|--------------------------------------------------------------------------
*/

case ARCHIVE = 'ArchiveValidator';

    case FILE_SIZE = 'FileSizeValidator';

    /*
    |--------------------------------------------------------------------------
    | Malware Protection
    |--------------------------------------------------------------------------
    */

    case VIRUS_SCAN = 'VirusScanner';

    case MALWARE_SANDBOX = 'MalwareSandbox';

    /*
    |--------------------------------------------------------------------------
    | File Integrity
    |--------------------------------------------------------------------------
    */

    case HASH = 'FileHash';

    case DUPLICATE = 'DuplicateDetector';

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    case QUARANTINE = 'FileQuarantine';

    case STORAGE = 'SecureFileStorage';

    /*
    |--------------------------------------------------------------------------
    | Import Validation
    |--------------------------------------------------------------------------
    */

    case STRUCTURE = 'ImportStructureValidator';

    case HEADER = 'HeaderValidator';

    case DATA = 'ImportDataValidator';

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    case AUTHORIZATION = 'AuthorizationValidator';

    case SCHOOL_OWNERSHIP = 'SchoolOwnershipValidator';

    case RATE_LIMIT = 'UploadRateLimiter';

    /**
     * Human-readable name.
     */
    public function label(): string
    {
        return match ($this) {

            self::EXTENSION => 'Extension Validation',

            self::MIME => 'MIME Validation',

            self::MAGIC_NUMBER => 'Magic Number Validation',

            self::OFFICE_DOCUMENT => 'Office Document Validation',

            self::ARCHIVE => 'Archive Validation',

            self::FILE_SIZE => 'File Size Validation',

            self::VIRUS_SCAN => 'Virus Scan',

            self::MALWARE_SANDBOX => 'Malware Sandbox',

            self::HASH => 'SHA-256 Hash',

            self::DUPLICATE => 'Duplicate Detection',

            self::QUARANTINE => 'Quarantine',

            self::STORAGE => 'Secure Storage',

            self::STRUCTURE => 'Import Structure Validation',

            self::HEADER => 'Header Validation',

            self::DATA => 'Import Data Validation',

            self::AUTHORIZATION => 'Authorization',

            self::SCHOOL_OWNERSHIP => 'School Ownership Validation',

            self::RATE_LIMIT => 'Rate Limiting',

        };
    }

    /**
     * Execution order.
     *
     * Lower values execute first.
     */
    public function order(): int
    {
        return match ($this) {

            self::AUTHORIZATION => 10,

            self::SCHOOL_OWNERSHIP => 20,

            self::RATE_LIMIT => 30,

            self::EXTENSION => 40,

            self::FILE_SIZE => 50,

            self::MIME => 60,

            self::MAGIC_NUMBER => 70,

            self::OFFICE_DOCUMENT => 75,
            self::ARCHIVE => 76,

            self::VIRUS_SCAN => 80,

            self::MALWARE_SANDBOX => 90,

            self::HASH => 100,

            self::DUPLICATE => 110,

            self::QUARANTINE => 120,

            self::STRUCTURE => 130,

            self::HEADER => 140,

            self::DATA => 150,

            self::STORAGE => 160,

        };
    }

    /**
     * Whether this validator blocks upload
     * when it fails.
     */
    public function isCritical(): bool
    {
        return match ($this) {

            self::EXTENSION,
self::FILE_SIZE,
self::MIME,
self::MAGIC_NUMBER,
self::OFFICE_DOCUMENT,
self::ARCHIVE,
self::VIRUS_SCAN,
self::HASH,
self::AUTHORIZATION,
self::SCHOOL_OWNERSHIP
    => true,

            default
                => false,

        };
    }
}
