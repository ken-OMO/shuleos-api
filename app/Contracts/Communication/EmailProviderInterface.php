<?php

namespace App\Contracts\Communication;

use App\Services\Communication\ProviderDeliveryResult;

interface EmailProviderInterface
{
    public function send(array $message): ProviderDeliveryResult;

    public function healthy(): bool;
}
