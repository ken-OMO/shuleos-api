<?php

declare(strict_types=1);

namespace App\Core\Security\File;

final readonly class SecurityIssue
{
    /**
     * Create a detected security issue.
     *
     * @param array<string,mixed> $context
     */
    public function __construct(

        public SecurityIssueCode $code,

        public array $context = []

    ) {
    }

    /**
     * Issue identifier.
     */
    public function id(): string
    {
        return $this->code->value;
    }

    /**
     * Human-readable description.
     */
    public function description(): string
    {
        return SecurityCatalog::description($this->code);
    }

    /**
     * Recommended action.
     */
    public function recommendation(): string
    {
        return SecurityCatalog::recommendation($this->code);
    }

    /**
     * Default severity.
     */
    public function severity(): SecuritySeverity
    {
        return SecurityCatalog::severity($this->code);
    }

    /**
     * Issue category.
     */
   public function category(): SecurityIssueCategory
    {
        return SecurityCatalog::category($this->code);
    }

    /**
     * Export issue.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [

            'code' => $this->id(),

            'category' => $this->category(),

            'severity' => $this->severity()->name,

            'description' => $this->description(),

            'recommendation' => $this->recommendation(),

            'context' => $this->context,

        ];
    }
}
