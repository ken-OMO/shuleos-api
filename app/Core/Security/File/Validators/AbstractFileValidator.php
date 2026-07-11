<?php

declare(strict_types=1);

namespace App\Core\Security\File\Validators;

use App\Core\Security\File\Contracts\FileValidator;
use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileSecurityReport;
use App\Core\Security\File\SecurityIssue;
use App\Core\Security\File\SecurityIssueCode;
use App\Core\Security\File\SecurityValidator;

/**
 * Base class for all file validators.
 *
 * Eliminates duplicated validator logic.
 */
abstract class AbstractFileValidator implements FileValidator
{
    /**
     * Default execution priority.
     */
    public function priority(): int
    {
        return $this->name()->order();
    }

    /**
     * Whether this validator supports
     * the supplied policy.
     *
     * Validators with conditional execution
     * may override this method.
     */
    public function supports(
        FilePolicy $policy
    ): bool {

        return true;

    }

    /**
     * Record a successful validation.
     */
    protected function pass(
        FileSecurityReport $report
    ): void {

        $report->addValidatorResult(

            $this->name(),

            true

        );

    }

    /**
     * Record a failed validation.
     */
    protected function fail(

        FileSecurityReport $report,

        SecurityIssueCode $code,

        array $context = []

    ): void {

        $report->addValidatorResult(

            $this->name(),

            false

        );

        $report->addIssue(

            new SecurityIssue(

                $code,

                $context

            )

        );

    }

    /**
     * Validator identifier.
     */
    abstract public function name(): SecurityValidator;
}
