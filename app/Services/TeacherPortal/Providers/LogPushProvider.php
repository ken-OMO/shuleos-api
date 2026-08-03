<?php

namespace App\Services\TeacherPortal\Providers;

use App\Contracts\TeacherPortal\PushProviderInterface;
use App\Services\TeacherPortal\PushDeliveryResult;
use Illuminate\Support\Facades\Log;

class LogPushProvider implements PushProviderInterface
{
    public function send(array $message): PushDeliveryResult
    {
        Log::info('Teacher push accepted by log provider.', ['idempotency_key' => $message['idempotency_key']]);

        return new PushDeliveryResult(true, 'log', 'log-'.hash('sha256', $message['idempotency_key']));
    }
}
