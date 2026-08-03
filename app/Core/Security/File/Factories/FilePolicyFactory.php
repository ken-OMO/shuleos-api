<?php

declare(strict_types=1);

namespace App\Core\Security\File\Factories;

use App\Core\Security\File\FilePolicy;

/**
 * Factory responsible for creating predefined
 * immutable file upload policies.
 *
 * Every upload within ShuleOS must obtain
 * its FilePolicy from this factory.
 */
final class FilePolicyFactory
{
    /**
     * One kilobyte.
     */
    private const KB = 1024;

    /**
     * One megabyte.
     */
    private const MB = 1024 * self::KB;

    /**
     * One gigabyte.
     */
    private const GB = 1024 * self::MB;

    /**
     * Prevent instantiation.
     */
    private function __construct() {}
    /*
    |--------------------------------------------------------------------------
    | Base Policy Builder
    |--------------------------------------------------------------------------
    */

    /**
     * Create a secure base policy.
     *
     * All ShuleOS upload policies inherit from
     * this configuration unless explicitly overridden.
     *
     * @param  list<string>  $allowedExtensions
     * @param  list<string>  $allowedMimeTypes
     */
    private static function basePolicy(

        string $policyName,

        array $allowedExtensions,

        array $allowedMimeTypes,

        int $maximumFileSize

    ): FilePolicy {

        return new FilePolicy(

            /*
            |--------------------------------------------------------------------------
            | Policy
            |--------------------------------------------------------------------------
            */

            policyName: $policyName,

            /*
            |--------------------------------------------------------------------------
            | File Types
            |--------------------------------------------------------------------------
            */

            allowedExtensions: array_map(

                static fn (string $extension): string => strtolower(trim($extension)),

                $allowedExtensions

            ),

            allowedMimeTypes: array_map(

                static fn (string $mime): string => strtolower(trim($mime)),

                $allowedMimeTypes

            ),

            /*
            |--------------------------------------------------------------------------
            | Limits
            |--------------------------------------------------------------------------
            */

            maximumFileSize: $maximumFileSize,

            /*
            |--------------------------------------------------------------------------
            | Core Security
            |--------------------------------------------------------------------------
            */

            requireVirusScan: true,

            requireMagicNumberValidation: true,

            requireMimeValidation: true,

            requireHashing: true,

            requireQuarantine: true,

            encryptAfterUpload: true,

            deleteAfterProcessing: true,

            auditUploads: true,

            /*
            |--------------------------------------------------------------------------
            | Advanced Security
            |--------------------------------------------------------------------------
            */

            scanArchives: true,

            requireMalwareSandbox: false,

            requireDigitalSignature: false,

            requireSchoolOwnershipValidation: true,

            /*
            |--------------------------------------------------------------------------
            | Office Documents
            |--------------------------------------------------------------------------
            */

            allowMacros: false,

            allowPasswordProtectedFiles: false,

            /*
            |--------------------------------------------------------------------------
            | Validation
            |--------------------------------------------------------------------------
            */

            failFast: true,

            /*
            |--------------------------------------------------------------------------
            | Upload Behaviour
            |--------------------------------------------------------------------------
            */

            allowDuplicateFiles: false,

            allowOverwrite: false,

            keepOriginalFilename: false,

            generateThumbnail: false

        );

    }
    /*
|--------------------------------------------------------------------------
| Policy Builder
|--------------------------------------------------------------------------
*/

    /**
     * Create a policy with optional overrides.
     *
     * @param  list<string>  $allowedExtensions
     * @param  list<string>  $allowedMimeTypes
     */
    private static function createPolicy(
        string $policyName,
        array $allowedExtensions,
        array $allowedMimeTypes,
        int $maximumFileSize,
        ?int $maximumImportRows = null,
        ?int $maximumImportColumns = null,
        bool $generateThumbnail = false,
        bool $allowMacros = false,
        bool $allowPasswordProtectedFiles = false,
        bool $requireDigitalSignature = false
    ): FilePolicy {

        return new FilePolicy(

            policyName: $policyName,

            allowedExtensions: array_map(
                static fn (string $extension): string => strtolower(trim($extension)),
                $allowedExtensions
            ),

            allowedMimeTypes: array_map(
                static fn (string $mime): string => strtolower(trim($mime)),
                $allowedMimeTypes
            ),

            maximumFileSize: $maximumFileSize,

            maximumImportRows: $maximumImportRows,

            maximumImportColumns: $maximumImportColumns,

            requireVirusScan: true,

            requireMagicNumberValidation: true,

            requireMimeValidation: true,

            requireHashing: true,

            requireQuarantine: true,

            encryptAfterUpload: true,

            deleteAfterProcessing: true,

            auditUploads: true,

            scanArchives: true,

            requireMalwareSandbox: false,

            requireDigitalSignature: $requireDigitalSignature,

            requireSchoolOwnershipValidation: true,

            allowMacros: $allowMacros,

            allowPasswordProtectedFiles: $allowPasswordProtectedFiles,

            failFast: true,

            allowDuplicateFiles: false,

            allowOverwrite: false,

            keepOriginalFilename: false,

            generateThumbnail: $generateThumbnail

        );

    }
    /*
    |--------------------------------------------------------------------------
    | Core Academic Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Teacher Import Policy.
     */
    public static function teacherImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Teacher Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 10 * self::MB,

            maximumImportRows: 10000,

            maximumImportColumns: 100

        );
    }

    /**
     * Learner Import Policy.
     */
    public static function learnerImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Learner Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 20 * self::MB,

            maximumImportRows: 50000,

            maximumImportColumns: 150

        );
    }

    /**
     * Parent Import Policy.
     */
    public static function parentImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Parent Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 10 * self::MB,

            maximumImportRows: 30000,

            maximumImportColumns: 120

        );
    }

    /**
     * Staff Import Policy.
     */
    public static function staffImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Staff Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 10 * self::MB,

            maximumImportRows: 10000,

            maximumImportColumns: 120

        );
    }

    /**
     * Marks Import Policy.
     */
    public static function marksImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Marks Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 15 * self::MB,

            maximumImportRows: 100000,

            maximumImportColumns: 250

        );
    }

    /**
     * CBC Assessment Import Policy.
     */
    public static function cbcAssessmentImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'CBC Assessment Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 20 * self::MB,

            maximumImportRows: 150000,

            maximumImportColumns: 300

        );
    }
    /*
    |--------------------------------------------------------------------------
    | Document Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Report Card Policy.
     */
    public static function reportCard(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Report Card',

            allowedExtensions: [

                'pdf',

            ],

            allowedMimeTypes: [

                'application/pdf',

            ],

            maximumFileSize: 15 * self::MB

        );
    }

    /**
     * Staff Document Policy.
     */
    public static function staffDocument(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Staff Document',

            allowedExtensions: [

                'pdf',

                'docx',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

            ],

            maximumFileSize: 25 * self::MB

        );
    }

    /**
     * Learner Document Policy.
     */
    public static function learnerDocument(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Learner Document',

            allowedExtensions: [

                'pdf',

                'docx',

                'jpg',

                'jpeg',

                'png',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

                'image/jpeg',

                'image/png',

            ],

            maximumFileSize: 15 * self::MB

        );
    }

    /**
     * Profile Photo Policy.
     */
    public static function profilePhoto(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Profile Photo',

            allowedExtensions: [

                'jpg',

                'jpeg',

                'png',

                'webp',

            ],

            allowedMimeTypes: [

                'image/jpeg',

                'image/png',

                'image/webp',

            ],

            maximumFileSize: 5 * self::MB,

            generateThumbnail: true

        );
    }

    /**
     * School Logo Policy.
     */
    public static function schoolLogo(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'School Logo',

            allowedExtensions: [

                'png',

                'svg',

                'jpg',

                'jpeg',

                'webp',

            ],

            allowedMimeTypes: [

                'image/png',

                'image/svg+xml',

                'image/jpeg',

                'image/webp',

            ],

            maximumFileSize: 5 * self::MB,

            generateThumbnail: true

        );
    }

    /**
     * Curriculum Document Policy.
     */
    public static function curriculumDocument(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Curriculum Document',

            allowedExtensions: [

                'pdf',

                'docx',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

            ],

            maximumFileSize: 30 * self::MB

        );
    }

    /**
     * Lesson Note Policy.
     */
    public static function lessonNote(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Lesson Note',

            allowedExtensions: [

                'pdf',

                'docx',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

            ],

            maximumFileSize: 20 * self::MB

        );
    }

    /**
     * Scheme of Work Policy.
     */
    public static function schemeOfWork(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Scheme of Work',

            allowedExtensions: [

                'pdf',

                'docx',

                'xlsx',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 30 * self::MB

        );
    }
    /*
    |--------------------------------------------------------------------------
    | Finance Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Finance Import Policy.
     */
    public static function financeImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Finance Import',

            allowedExtensions: [

                'csv',

                'xlsx',

            ],

            allowedMimeTypes: [

                'text/csv',

                'application/vnd.ms-excel',

                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            ],

            maximumFileSize: 30 * self::MB,

            maximumImportRows: 100000,

            maximumImportColumns: 250

        );
    }

    /**
     * Payment Receipt Policy.
     */
    public static function paymentReceipt(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Payment Receipt',

            allowedExtensions: [

                'pdf',

                'jpg',

                'jpeg',

                'png',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'image/jpeg',

                'image/png',

            ],

            maximumFileSize: 10 * self::MB

        );
    }

    /*
    |--------------------------------------------------------------------------
    | Library Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Library Book Policy.
     */
    public static function libraryBook(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Library Book',

            allowedExtensions: [

                'pdf',

                'epub',

            ],

            allowedMimeTypes: [

                'application/pdf',

                'application/epub+zip',

            ],

            maximumFileSize: 100 * self::MB

        );
    }

    /*
    |--------------------------------------------------------------------------
    | Backup Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Backup Archive Policy.
     */
    public static function backupArchive(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Backup Archive',

            allowedExtensions: [

                'zip',

            ],

            allowedMimeTypes: [

                'application/zip',

            ],

            maximumFileSize: 2 * self::GB

        );
    }

    /*
    |--------------------------------------------------------------------------
    | System Policies
    |--------------------------------------------------------------------------
    */

    /**
     * Database Restore Policy.
     */
    public static function databaseRestore(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'Database Restore',

            allowedExtensions: [

                'sql',

                'zip',

            ],

            allowedMimeTypes: [

                'application/sql',

                'application/zip',

                'text/plain',

            ],

            maximumFileSize: 500 * self::MB

        );
    }

    /**
     * System Update Policy.
     */
    public static function systemUpdate(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'System Update',

            allowedExtensions: [

                'zip',

            ],

            allowedMimeTypes: [

                'application/zip',

            ],

            maximumFileSize: self::GB,

            requireDigitalSignature: true

        );
    }

    /**
     * API Import Policy.
     */
    public static function apiImport(): FilePolicy
    {
        return self::createPolicy(

            policyName: 'API Import',

            allowedExtensions: [

                'json',

            ],

            allowedMimeTypes: [

                'application/json',

            ],

            maximumFileSize: 50 * self::MB

        );
    }

    public static function learningResource(): FilePolicy
    {
        return self::createPolicy(
            policyName: 'Learning Resource',
            allowedExtensions: ['pdf', 'docx', 'pptx', 'xlsx', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'mp3', 'm4a', 'mp4', 'webm'],
            allowedMimeTypes: ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain', 'image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/mp4', 'video/mp4', 'video/webm'],
            maximumFileSize: 250 * self::MB
        );
    }

    public static function homeworkSubmission(): FilePolicy
    {
        return self::createPolicy(
            policyName: 'Homework Submission',
            allowedExtensions: ['pdf', 'docx', 'pptx', 'xlsx', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'mp3', 'm4a', 'mp4', 'webm'],
            allowedMimeTypes: ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain', 'image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/mp4', 'video/mp4', 'video/webm'],
            maximumFileSize: 50 * self::MB
        );
    }
}
