<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Immutable binary file signature.
 *
 * Represents the leading bytes that identify
 * a file's true format.
 */
final readonly class MagicNumber
{
    /**
     * Create a magic number.
     */
    public function __construct(

        public string $extension,

        public string $signature,

        public int $offset = 0

    ) {

        $this->extension = strtolower(

            trim($this->extension)

        );

        $this->signature = strtoupper(

            preg_replace(

                '/\s+/',

                '',

                trim($this->signature)

            ) ?? ''

        );

    }

    /**
     * File extension.
     */
    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Magic number signature.
     */
    public function signature(): string
    {
        return $this->signature;
    }

    /**
     * Signature offset.
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Signature length in bytes.
     */
    public function length(): int
    {
        return intdiv(

            strlen($this->signature),

            2

        );
    }

    /**
     * Determine whether this signature
     * matches the supplied hexadecimal data.
     */
    public function matches(
        string $hexData
    ): bool {

        $hexData = strtoupper(

            preg_replace(

                '/\s+/',

                '',

                $hexData

            ) ?? ''

        );

        $start = $this->offset * 2;

        return substr(

            $hexData,

            $start,

            strlen($this->signature)

        ) === $this->signature;

    }

    /**
     * Determine whether the signature
     * is empty.
     */
    public function isEmpty(): bool
    {
        return $this->signature === '';
    }

    /**
     * Export the magic number.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [

            'extension' => $this->extension,

            'signature' => $this->signature,

            'offset' => $this->offset,

            'length' => $this->length(),

        ];
    }

    /**
     * String representation.
     */
    public function __toString(): string
    {
        return $this->signature;
    }
}
