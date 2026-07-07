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
 * Validates compressed archives.
 *
 * Detects dangerous archives including:
 *
 * - ZIP bombs
 * - Dangerous executable files
 * - Excessive file counts
 * - Excessive extracted size
 */
final class ArchiveValidator implements FileValidator
{
    /**
     * Maximum allowed files inside
     * an archive.
     */
    private const MAX_FILES = 1000;

    /**
     * Maximum allowed extracted size.
     */
    private const MAX_UNCOMPRESSED_SIZE = 500 * 1024 * 1024;

    /**
     * Maximum compression ratio.
     */
    private const MAX_COMPRESSION_RATIO = 100;

    /**
     * Dangerous extensions.
     *
     * @var list<string>
     */
    private const DANGEROUS_EXTENSIONS = [

        'exe',

        'dll',

        'bat',

        'cmd',

        'ps1',

        'vbs',

        'js',

        'jar',

        'msi',

        'scr',

        'com',

    ];

    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::ARCHIVE;
    }
        /**
     * Validate an archive.
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

                    'zip',

                    'docx',

                    'xlsx',

                    'pptx',

                    'apk',

                ],

                true

            )

        ) {

            return;

        }

        $zip = new ZipArchive();

        if (

            $zip->open(

                $file->getRealPath()

            ) !== true

        ) {

            return;

        }

        $fileCount = 0;

        $compressedSize = filesize(

            $file->getRealPath()

        ) ?: 0;

        $uncompressedSize = 0;

        for (

            $index = 0;

            $index < $zip->numFiles;

            $index++

        ) {

            $stat = $zip->statIndex(

                $index

            );

            if (

                $stat === false

            ) {

                continue;

            }

            $fileCount++;

            $uncompressedSize +=

                (int) ($stat['size'] ?? 0);

            $entry = strtolower(

                $stat['name'] ?? ''

            );

            if (

                $this->containsDangerousExtension(

                    $entry

                )

            ) {

                $report->addIssue(

                    new SecurityIssue(

                        SecurityIssueCode::SUSPICIOUS_FILE,

                        [

                            'entry' => $entry,

                            'archive' => $file->getClientOriginalName(),

                        ]

                    )

                );

            }

        }

        $zip->close();

        if (

            $fileCount > self::MAX_FILES

        ) {

            $report->addIssue(

                new SecurityIssue(

                    SecurityIssueCode::INVALID_DATA,

                    [

                        'files' => $fileCount,

                        'maximum' => self::MAX_FILES,

                    ]

                )

            );

        }

        if (

            $uncompressedSize >

            self::MAX_UNCOMPRESSED_SIZE

        ) {

            $report->addIssue(

                new SecurityIssue(

                    SecurityIssueCode::FILE_TOO_LARGE,

                    [

                        'size' => $uncompressedSize,

                    ]

                )

            );

        }

        if (

            $compressedSize > 0

        ) {

            $ratio =

                $uncompressedSize /

                $compressedSize;

            if (

                $ratio >

                self::MAX_COMPRESSION_RATIO

            ) {

                $report->addIssue(

                    new SecurityIssue(

                        SecurityIssueCode::SUSPICIOUS_FILE,

                        [

                            'compression_ratio' => round(

                                $ratio,

                                2

                            ),

                        ]

                    )

                );

            }

        }

    }
        /**
     * Determine whether an archive entry
     * contains a dangerous file extension.
     */
    private function containsDangerousExtension(
        string $entry
    ): bool {

        $extension = strtolower(

            pathinfo(

                $entry,

                PATHINFO_EXTENSION

            )

        );

        return in_array(

            $extension,

            self::DANGEROUS_EXTENSIONS,

            true

        );

    }

    /**
     * Determine whether this validator
     * supports the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {

        return $policy->scanArchives;

    }

    /**
     * Validator execution priority.
     *
     * Lower numbers execute first.
     */
    public function priority(): int
    {
        return 50;
    }
}
