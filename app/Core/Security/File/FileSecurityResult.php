<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use DateTimeImmutable;

final class FileSecurityResult
{
    /**
     * Security issues discovered.
     *
     * @var SecurityIssue[]
     */
    private array $issues = [];

    /**
     * Validator execution results.
     *
     * @var array<string, bool>
     */
    private array $validators = [];

    /**
     * Scan started.
     */
    private readonly DateTimeImmutable $startedAt;

    /**
     * Scan completed.
     */
    private ?DateTimeImmutable $completedAt = null;

    /**
     * Constructor.
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly string $fileName,
        public readonly string $sha256
    ) {

        $this->startedAt = new DateTimeImmutable();

    }

    /**
     * Record validator execution.
     */
    public function addValidatorResult(
        string $validator,
        bool $passed
    ): void {

        $this->validators[$validator] = $passed;

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
     * Finish scan.
     */
    public function finish(): void
    {

        $this->completedAt = new DateTimeImmutable();

    }

    /**
     * Determine whether the file passed.
     */
    public function passed(): bool
    {

        foreach ($this->issues as $issue) {

            if ($issue->severity->blocksUpload()) {

                return false;

            }

        }

        return true;

    }

    /**
     * Security score.
     */
    public function score(): int
    {

        $score = 100;

        foreach ($this->issues as $issue) {

            $score -= $issue->severity->score();

        }

        return max(0, $score);

    }

    /**
     * Highest severity detected.
     */
    public function highestSeverity(): SecuritySeverity
    {

        $highest = SecuritySeverity::INFO;

        foreach ($this->issues as $issue) {

            if ($issue->severity->value > $highest->value) {

                $highest = $issue->severity;

            }

        }

        return $highest;

    }

    /**
     * Scan duration in milliseconds.
     */
    public function duration(): ?int
    {

        if ($this->completedAt === null) {

            return null;

        }

        return (int) (

            (
                (float) $this->completedAt->format('U.u')

                -

                (float) $this->startedAt->format('U.u')

            ) * 1000

        );

    }

    /**
     * All validator results.
     *
     * @return array<string,bool>
     */
    public function validators(): array
    {

        return $this->validators;

    }

    /**
     * All issues.
     *
     * @return SecurityIssue[]
     */
    public function issues(): array
    {

        return $this->issues;

    }

    /**
     * Number of issues.
     */
    public function issueCount(): int
    {

        return count($this->issues);

    }

    /**
     * Whether issues exist.
     */
    public function hasIssues(): bool
    {

        return ! empty($this->issues);

    }

    /**
     * Whether validator was executed.
     */
    public function validatorExecuted(
        string $validator
    ): bool
    {

        return array_key_exists(

            $validator,

            $this->validators

        );

    }

    /**
     * Whether validator passed.
     */
    public function validatorPassed(
        string $validator
    ): bool
    {

        return $this->validators[$validator] ?? false;

    }

    /**
     * Scan started at.
     */
    public function startedAt(): DateTimeImmutable
    {

        return $this->startedAt;

    }

    /**
     * Scan completed at.
     */
    public function completedAt(): ?DateTimeImmutable
    {

        return $this->completedAt;

    }

    /**
     * Export result.
     */
    public function toArray(): array
    {

        return [

            'upload_id' => $this->uploadId,

            'file_name' => $this->fileName,

            'sha256' => $this->sha256,

            'passed' => $this->passed(),

            'score' => $this->score(),

            'severity' => $this->highestSeverity()->name,

            'duration_ms' => $this->duration(),

            'validators' => $this->validators,

            'issues' => array_map(

                static fn (SecurityIssue $issue) => [

                    'code' => $issue->code->value,

                    'severity' => $issue->severity->name,

                    'message' => $issue->message,

                    'recommendation' => $issue->recommendation,

                ],

                $this->issues

            ),

            'started_at' => $this->startedAt->format(DATE_ATOM),

            'completed_at' => $this->completedAt?->format(DATE_ATOM),

        ];

    }
}
