<?php

declare(strict_types=1);

namespace App\Core\Identifier;

use Illuminate\Support\Str;

final class Identifier
{
    /**
     * Generate a new UUID.
     */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Determine whether the given value is a valid UUID.
     */
    public static function isValid(string $id): bool
    {
        return Str::isUuid($id);
    }
}
