<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\FileValidator;
use App\Core\Security\File\Contracts\VirusScannerInterface;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssue;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Virus scanner validator.
 *
 * Delegates malware scanning to the
 * configured virus scanner implementation.
 */
final class VirusScanner implements FileValidator
{
    /**
     * Create a new validator.
     */
    public function __construct(

        private readonly VirusScannerInterface $scanner

    ) {
    }

    /**
     * Validator identifier.
     */
    public function name(): SecurityValidator
    {
        return SecurityValidator::VIRUS_SCAN;
    }

    /**
     * Scan the uploaded file for malware.
     */
    public function validate(
        UploadedFile $file,
        FilePolicy $policy,
        FileSecurityReport $report
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Virus Scanning Disabled
        |--------------------------------------------------------------------------
        */

        if (

            ! $policy->scansForViruses()

        ) {

            return;

        }

        /*
        |--------------------------------------------------------------------------
        | Scanner Not Available
        |--------------------------------------------------------------------------
        */

        if (

            ! $this->scanner->available()

        ) {

            $report->addValidatorResult(

                SecurityValidator::VIRUS_SCAN,

                false

            );

            return;

        }

        /*
        |--------------------------------------------------------------------------
        | Execute Scan
        |--------------------------------------------------------------------------
        */

        $clean = $this->scanner->scan(

            $file

        );

        $report->addValidatorResult(

            SecurityValidator::VIRUS_SCAN,

            $clean

        );

        if (

            $clean

        ) {

            return;

        }

        /*
        |--------------------------------------------------------------------------
        | Malware Detected
        |--------------------------------------------------------------------------
        */

        $report->addIssue(

            new SecurityIssue(

                SecurityIssueCode::VIRUS_DETECTED,

                [

                    'scanner' => $this->scanner->name(),

                    'threat' => $this->scanner->detectedThreat(),

                    'file_name' => $file->getClientOriginalName(),

                ]

            )

        );

    }

    /**
     * Determine whether this validator
     * supports the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool
    {
        return $policy->scansForViruses();
    }

    /**
     * Validator execution priority.
     *
     * Lower numbers execute first.
     */
    public function priority(): int
    {
        return SecurityValidator::VIRUS_SCAN->order();
    }
}
