<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\File\MagicNumber;
use App\Core\Security\File\MagicNumberDatabase;
use PHPUnit\Framework\TestCase;

class MagicNumberTest extends TestCase
{
    public function test_constructor_normalizes_signatures_and_preserves_offsets(): void
    {
        $signature = new MagicNumber(
            extension: ' PNG ',
            signature: " 89 50 4e 47 \n",
            offset: 2
        );

        $this->assertSame('png', $signature->extension);
        $this->assertSame('89504E47', $signature->signature);
        $this->assertSame(2, $signature->offset());
        $this->assertSame(4, $signature->length());
        $this->assertTrue($signature->matches('0000 89504e47'));
        $this->assertFalse($signature->matches('0000 89504e48'));
    }

    public function test_database_signatures_can_be_constructed_and_match_png_bytes(): void
    {
        $signatures = MagicNumberDatabase::forExtension('png');

        $this->assertNotEmpty($signatures);
        $this->assertTrue($signatures[0]->matches('89504E470D0A1A0A'));
        $this->assertFalse($signatures[0]->matches('2550444600000000'));
    }
}
