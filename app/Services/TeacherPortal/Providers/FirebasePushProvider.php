<?php

namespace App\Services\TeacherPortal\Providers;

use App\Contracts\TeacherPortal\PushProviderInterface;
use App\Services\TeacherPortal\PushDeliveryResult;

class FirebasePushProvider implements PushProviderInterface
{
    public function send(array $message): PushDeliveryResult
    {
        return new PushDeliveryResult(false, 'firebase', failureCode: 'provider_not_configured');
    }
}
