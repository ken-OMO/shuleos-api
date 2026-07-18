<?php

namespace App\Services\TeacherPortal;

final readonly class PushDeliveryResult
{
    public function __construct(public bool $accepted, public string $provider, public ?string $messageId = null, public ?string $failureCode = null, public bool $invalidToken = false) {}
}
