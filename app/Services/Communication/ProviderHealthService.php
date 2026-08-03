<?php

namespace App\Services\Communication;

use App\Contracts\Communication\EmailProviderInterface;
use App\Contracts\Communication\SmsProviderInterface;

class ProviderHealthService
{
    public function __construct(private EmailProviderInterface $email, private SmsProviderInterface $sms) {}

    public function status(): array
    {
        return ['email' => ['provider' => config('communication.email.provider'), 'ready' => $this->email->healthy()], 'sms' => ['provider' => config('communication.sms.provider'), 'enabled' => config('communication.sms.enabled'), 'ready' => config('communication.sms.enabled') && $this->sms->healthy()]];
    }
}
