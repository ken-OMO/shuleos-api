<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Central metadata catalog for all security issues.
 *
 * This class is the single source of truth for:
 * - Descriptions
 * - Recommendations
 * - Severity
 * - Categories
 */
final class SecurityCatalog
{
    /**
     * Prevent instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Human-readable description.
     */
    public static function description(
        SecurityIssueCode $code
    ): string {

        return match ($code) {

            SecurityIssueCode::INVALID_EXTENSION =>
                'The uploaded file extension is not allowed.',

            SecurityIssueCode::INVALID_MIME_TYPE =>
                'The uploaded file MIME type is not allowed.',

            SecurityIssueCode::INVALID_MAGIC_NUMBER =>
                'The uploaded file signature does not match its extension.',

            SecurityIssueCode::FILE_TOO_LARGE =>
                'The uploaded file exceeds the maximum permitted size.',

            SecurityIssueCode::FILE_TOO_MANY_ROWS =>
                'The uploaded file exceeds the maximum allowed rows.',

            SecurityIssueCode::FILE_TOO_MANY_COLUMNS =>
                'The uploaded file exceeds the maximum allowed columns.',

            SecurityIssueCode::VIRUS_DETECTED =>
                'A virus has been detected.',

            SecurityIssueCode::MALWARE_DETECTED =>
                'Malware has been detected.',

            SecurityIssueCode::SUSPICIOUS_FILE =>
                'The uploaded file appears suspicious.',

            SecurityIssueCode::SANDBOX_ANALYSIS_FAILED =>
                'Sandbox analysis failed.',

            SecurityIssueCode::MACRO_DETECTED =>
                'Macros were detected in the document.',

            SecurityIssueCode::PASSWORD_PROTECTED =>
                'Password-protected files are not allowed.',

            SecurityIssueCode::DIGITAL_SIGNATURE_REQUIRED =>
                'A digital signature is required.',

            SecurityIssueCode::INVALID_DIGITAL_SIGNATURE =>
                'The digital signature is invalid.',

            SecurityIssueCode::HASH_MISMATCH =>
                'File integrity verification failed.',

            SecurityIssueCode::DUPLICATE_FILE =>
                'An identical file already exists.',

            SecurityIssueCode::FILE_CORRUPTED =>
                'The uploaded file appears corrupted.',

            SecurityIssueCode::UNAUTHORIZED_UPLOAD =>
                'The user is not authorized to upload this file.',

            SecurityIssueCode::SCHOOL_OWNERSHIP_FAILED =>
                'The upload does not belong to the current school.',

            SecurityIssueCode::RATE_LIMIT_EXCEEDED =>
                'Upload rate limit exceeded.',

            SecurityIssueCode::QUARANTINE_FAILED =>
                'Failed to quarantine the uploaded file.',

            SecurityIssueCode::STORAGE_FAILED =>
                'Failed to store the uploaded file securely.',

            SecurityIssueCode::ENCRYPTION_FAILED =>
                'Failed to encrypt the uploaded file.',

            SecurityIssueCode::DELETE_FAILED =>
                'Failed to securely delete the temporary file.',

            SecurityIssueCode::INVALID_IMPORT_STRUCTURE =>
                'The import file structure is invalid.',

            SecurityIssueCode::INVALID_HEADER =>
                'The import headers are invalid.',

            SecurityIssueCode::INVALID_DATA =>
                'The import contains invalid data.',

            SecurityIssueCode::IMPORT_ABORTED =>
                'The import was aborted.',

        };

    }

    /**
     * Recommended action.
     */
    public static function recommendation(
        SecurityIssueCode $code
    ): string {

        return match ($code) {

            SecurityIssueCode::VIRUS_DETECTED =>
                'Delete the file immediately and obtain a clean copy.',

            SecurityIssueCode::MALWARE_DETECTED =>
                'Run a full antivirus scan before uploading again.',

            SecurityIssueCode::INVALID_EXTENSION =>
                'Upload a supported file type.',

            SecurityIssueCode::INVALID_MIME_TYPE =>
                'Ensure the uploaded file is of the correct format.',

            default =>
                'Correct the issue and try again.',

        };

    }

    /**
     * Default severity.
     */
    public static function severity(
        SecurityIssueCode $code
    ): SecuritySeverity {

        return match ($code) {

            SecurityIssueCode::VIRUS_DETECTED,
            SecurityIssueCode::MALWARE_DETECTED =>
                SecuritySeverity::CRITICAL,

            SecurityIssueCode::HASH_MISMATCH,
            SecurityIssueCode::FILE_CORRUPTED,
            SecurityIssueCode::UNAUTHORIZED_UPLOAD =>
                SecuritySeverity::HIGH,

            SecurityIssueCode::INVALID_EXTENSION,
            SecurityIssueCode::INVALID_MIME_TYPE,
            SecurityIssueCode::INVALID_MAGIC_NUMBER,
            SecurityIssueCode::FILE_TOO_LARGE,
            SecurityIssueCode::PASSWORD_PROTECTED =>
                SecuritySeverity::MEDIUM,

            default =>
                SecuritySeverity::LOW,

        };

    }

    /**
     * Security category.
     */
    public static function category(
        SecurityIssueCode $code
    ): SecurityIssueCategory {

        return match ($code) {

            SecurityIssueCode::INVALID_EXTENSION,
            SecurityIssueCode::INVALID_MIME_TYPE,
            SecurityIssueCode::INVALID_MAGIC_NUMBER,
            SecurityIssueCode::FILE_TOO_LARGE,
            SecurityIssueCode::FILE_TOO_MANY_ROWS,
            SecurityIssueCode::FILE_TOO_MANY_COLUMNS =>
                SecurityIssueCategory::VALIDATION,

            SecurityIssueCode::VIRUS_DETECTED,
            SecurityIssueCode::MALWARE_DETECTED,
            SecurityIssueCode::SUSPICIOUS_FILE,
            SecurityIssueCode::SANDBOX_ANALYSIS_FAILED =>
                SecurityIssueCategory::MALWARE,

            SecurityIssueCode::MACRO_DETECTED,
            SecurityIssueCode::PASSWORD_PROTECTED,
            SecurityIssueCode::DIGITAL_SIGNATURE_REQUIRED,
            SecurityIssueCode::INVALID_DIGITAL_SIGNATURE =>
                SecurityIssueCategory::OFFICE_DOCUMENT,

            SecurityIssueCode::HASH_MISMATCH,
            SecurityIssueCode::DUPLICATE_FILE,
            SecurityIssueCode::FILE_CORRUPTED =>
                SecurityIssueCategory::FILE_INTEGRITY,

            SecurityIssueCode::UNAUTHORIZED_UPLOAD,
            SecurityIssueCode::SCHOOL_OWNERSHIP_FAILED,
            SecurityIssueCode::RATE_LIMIT_EXCEEDED =>
                SecurityIssueCategory::AUTHORIZATION,

            SecurityIssueCode::QUARANTINE_FAILED,
            SecurityIssueCode::STORAGE_FAILED,
            SecurityIssueCode::ENCRYPTION_FAILED,
            SecurityIssueCode::DELETE_FAILED =>
                SecurityIssueCategory::STORAGE,

            SecurityIssueCode::INVALID_IMPORT_STRUCTURE,
            SecurityIssueCode::INVALID_HEADER,
            SecurityIssueCode::INVALID_DATA,
            SecurityIssueCode::IMPORT_ABORTED =>
                SecurityIssueCategory::IMPORT,

        };

    }
}
