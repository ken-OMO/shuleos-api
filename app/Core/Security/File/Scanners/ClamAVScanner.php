<?php

declare(strict_types=1);

namespace App\Core\Security\File\Scanners;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ClamAVScanner implements VirusScannerInterface
{
    private ?string $threat = null;

    public function available(): bool
    {
        return (new ExecutableFinder)->find('clamscan') !== null;
    }

    public function name(): string
    {
        return 'ClamAV';
    }

    public function detectedThreat(): ?string
    {
        return $this->threat;
    }

    public function scan(UploadedFile $file): bool
    {
        $this->threat = null;
        $executable = (new ExecutableFinder)->find('clamscan');
        if ($executable === null) {
            $this->threat = 'Scanner unavailable.';

            return false;
        }
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            $this->threat = 'Scanner input unavailable.';

            return false;
        }
        try {
            $process = new Process([$executable, '--no-summary', '--', $path]);
            $process->setTimeout(60);
            $status = $process->run();
            if ($status === 0) {
                return true;
            }
            $this->threat = $status === 1 ? mb_substr(trim($process->getOutput()), 0, 1000) : 'Scanner execution failed.';
        } catch (\Throwable) {
            $this->threat = 'Scanner execution failed.';
        }

        return false;
    }
}
