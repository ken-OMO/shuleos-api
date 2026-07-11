<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;
use Illuminate\Http\UploadedFile;

/**
 * Virus scanner validator.
 *
 * Delegates malware scanning to the configured
 * VirusScannerInterface implementation.
 */
final class VirusScanner extends AbstractFileValidator
{
    /**
     * Create the virus scanner validator.
     */
    public function __construct(
        private readonly VirusScannerInterface $scanner
    ) {}

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
        if (! $this->scanner->available()) {
            $this->fail(
                report: $report,
                code: SecurityIssueCode::SANDBOX_ANALYSIS_FAILED,
                context: [
                    'scanner' => $this->scanner->name(),
                    'file_name' => $file->getClientOriginalName(),
                    'reason' => 'The configured virus scanner is unavailable.',
                ]
            );

            return;
        }

        $clean = $this->scanner->scan($file);

        if ($clean) {
            $this->pass($report);

            return;
        }

        $this->fail(
            report: $report,
            code: SecurityIssueCode::VIRUS_DETECTED,
            context: [
                'scanner' => $this->scanner->name(),
                'threat' => $this->scanner->detectedThreat(),
                'file_name' => $file->getClientOriginalName(),
            ]
        );
    }

    /**
     * Determine whether this validator supports
     * the supplied policy.
     */
    public function supports(
        FilePolicy $policy
    ): bool {
        return $policy->scansForViruses();
    }
}
