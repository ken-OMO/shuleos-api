<?php

namespace App\Contracts\Communication;

use App\Services\Communication\ProviderDeliveryResult;

interface SmsProviderInterface
{
    public function send(array $message): ProviderDeliveryResult;

    public function healthy(): bool;
}
