<?php

declare(strict_types=1);

namespace App\Core\Security\File;

enum SecuritySeverity: int
{
    /**
     * Informational.
     */
    case INFO = 1;

    /**
     * Low risk.
     */
    case LOW = 2;

    /**
     * Medium risk.
     */
    case MEDIUM = 3;

    /**
     * High risk.
     */
    case HIGH = 4;

    /**
     * Critical risk.
     */
    case CRITICAL = 5;

    /**
     * Numeric score.
     */
    public function score(): int
    {
        return match ($this) {

            self::INFO => 0,

            self::LOW => 10,

            self::MEDIUM => 30,

            self::HIGH => 70,

            self::CRITICAL => 100,

        };
    }

    /**
     * Human readable label.
     */
    public function label(): string
    {
        return match ($this) {

            self::INFO => 'Info',

            self::LOW => 'Low',

            self::MEDIUM => 'Medium',

            self::HIGH => 'High',

            self::CRITICAL => 'Critical',

        };
    }

    /**
     * Whether upload should be rejected.
     */
    public function blocksUpload(): bool
    {
        return match ($this) {

            self::INFO,
            self::LOW => false,

            self::MEDIUM,
            self::HIGH,
            self::CRITICAL => true,

        };
    }
}
