<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Security\File\Contracts\VirusScannerInterface;
use Illuminate\Http\UploadedFile;

final class StudentElectionPhotoTestScanner implements VirusScannerInterface
{
    public bool $online = true;

    public bool $clean = true;

    public int $scans = 0;

    public function available(): bool
    {
        return $this->online;
    }

    public function scan(UploadedFile $file): bool
    {
        $this->scans++;

        return $this->online && $this->clean;
    }

    public function name(): string
    {
        return 'Deterministic TEST scanner';
    }

    public function detectedThreat(): ?string
    {
        return $this->clean ? null : 'Synthetic test rejection';
    }
}
