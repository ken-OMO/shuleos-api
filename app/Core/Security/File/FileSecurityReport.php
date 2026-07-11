<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Security validation report.
 *
 * Collects the results produced by all validators
 * during file security inspection.
 *
 * This object is shared across the validation
 * pipeline and accumulates all detected issues.
 */
final class FileSecurityReport
{
    /**
     * @var list<SecurityIssue>
     */
    private array $issues = [];

    /**
     * Validator execution results.
     *
     * @var array<string,bool>
     */
    private array $validatorResults = [];

    /**
     * Validation start time.
     */
    private readonly float $startedAt;

    /**
     * Validation end time.
     */
    private ?float $finishedAt = null;

    /**
     * Create a new report.
     */
    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Record validator result.
     */
    public function addValidatorResult(

        SecurityValidator $validator,

        bool $passed

    ): void {

        $this->validatorResults[

            $validator->value

        ] = $passed;

    }

    /**
     * Add a security issue.
     */
    public function addIssue(
        SecurityIssue $issue
    ): void {

        $this->issues[] = $issue;

    }

    /**
     * Validator results.
     *
     * @return array<string,bool>
     */
    public function validatorResults(): array
    {
        return $this->validatorResults;
    }

    /**
     * Complete the report.
     */
    public function finish(): void
    {
        $this->finishedAt = microtime(true);
    }

    /**
     * Determine whether validation passed.
     */
    public function passed(): bool
    {
        return empty($this->issues);
    }

    /**
     * Determine whether validation failed.
     */
    public function failed(): bool
    {
        return ! $this->passed();
    }

    /**
     * Number of detected issues.
     */
    public function issueCount(): int
    {
        return count($this->issues);
    }

    /**
     * Determine whether the report
     * contains issues of a given severity.
     */
    public function hasSeverity(
        SecuritySeverity $severity
    ): bool {

        foreach ($this->issues as $issue) {

            if ($issue->severity() === $severity) {

                return true;

            }

        }

        return false;

    }

    /**
     * Determine whether the report
     * contains a specific issue.
     */
    public function hasIssue(
        SecurityIssueCode $code
    ): bool {

        foreach ($this->issues as $issue) {

            if ($issue->code === $code) {

                return true;

            }

        }

        return false;

    }

    /**
     * Get all detected issues.
     *
     * @return list<SecurityIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * Validation duration in milliseconds.
     */
    public function duration(): float
    {
        return round(

            (

                ($this->finishedAt ?? microtime(true))

                -

                $this->startedAt

            ) * 1000,

            2

        );
    }

    /**
     * Export report.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [

            'passed' => $this->passed(),

            'failed' => $this->failed(),

            'issue_count' => $this->issueCount(),

            'duration_ms' => $this->duration(),

            'validators' => $this->validatorResults(),

            'issues' => array_map(

                static fn (SecurityIssue $issue) => $issue->toArray(),

                $this->issues

            ),

        ];
    }
}
