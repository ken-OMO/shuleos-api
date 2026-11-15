<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\File\Scanners\ClamAVScanner;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class StudentElectionScannerUnavailableTest extends TestCase
{
    public function test_unavailable_clamav_cannot_return_clean(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scanner-test-');
        file_put_contents($path, 'synthetic scan input');
        $previous = getenv('PATH');
        try {
            putenv('PATH=');
            $scanner = new ClamAVScanner;
            $this->assertFalse($scanner->available());
            $this->assertFalse($scanner->scan(new UploadedFile($path, 'scan.txt', 'text/plain', null, true)));
            $this->assertSame('Scanner unavailable.', $scanner->detectedThreat());
        } finally {
            $previous === false ? putenv('PATH') : putenv('PATH='.$previous);
            unlink($path);
        }
    }
}
