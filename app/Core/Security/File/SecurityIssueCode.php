<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Standard security issue codes.
 *
 * These codes are stable identifiers used throughout
 * the ShuleOS Security Framework.
 *
 * They should NEVER be changed once released.
 */
enum SecurityIssueCode: string
{
    /*
    |--------------------------------------------------------------------------
    | File Validation
    |--------------------------------------------------------------------------
    */

    case INVALID_EXTENSION = 'SEC-0001';
    case INVALID_MIME_TYPE = 'SEC-0002';
    case INVALID_MAGIC_NUMBER = 'SEC-0003';
    case FILE_TOO_LARGE = 'SEC-0004';
    case FILE_TOO_MANY_ROWS = 'SEC-0005';
    case FILE_TOO_MANY_COLUMNS = 'SEC-0006';

    /*
    |--------------------------------------------------------------------------
    | Malware
    |--------------------------------------------------------------------------
    */

    case VIRUS_DETECTED = 'SEC-0100';
    case MALWARE_DETECTED = 'SEC-0101';
    case SUSPICIOUS_FILE = 'SEC-0102';
    case SANDBOX_ANALYSIS_FAILED = 'SEC-0103';

    /*
    |--------------------------------------------------------------------------
    | Office Documents
    |--------------------------------------------------------------------------
    */

    case MACRO_DETECTED = 'SEC-0200';
    case PASSWORD_PROTECTED = 'SEC-0201';
    case DIGITAL_SIGNATURE_REQUIRED = 'SEC-0202';
    case INVALID_DIGITAL_SIGNATURE = 'SEC-0203';

    /*
    |--------------------------------------------------------------------------
    | File Integrity
    |--------------------------------------------------------------------------
    */

    case HASH_MISMATCH = 'SEC-0300';
    case DUPLICATE_FILE = 'SEC-0301';
    case FILE_CORRUPTED = 'SEC-0302';

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    case UNAUTHORIZED_UPLOAD = 'SEC-0400';
    case SCHOOL_OWNERSHIP_FAILED = 'SEC-0401';
    case RATE_LIMIT_EXCEEDED = 'SEC-0402';

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    case QUARANTINE_FAILED = 'SEC-0500';
    case STORAGE_FAILED = 'SEC-0501';
    case ENCRYPTION_FAILED = 'SEC-0502';
    case DELETE_FAILED = 'SEC-0503';

    /*
    |--------------------------------------------------------------------------
    | Import
    |--------------------------------------------------------------------------
    */

    case INVALID_IMPORT_STRUCTURE = 'SEC-0600';
    case INVALID_HEADER = 'SEC-0601';
    case INVALID_DATA = 'SEC-0602';
    case IMPORT_ABORTED = 'SEC-0603';
}
