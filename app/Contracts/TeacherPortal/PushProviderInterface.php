<?php

namespace App\Contracts\TeacherPortal;

use App\Services\TeacherPortal\PushDeliveryResult;

interface PushProviderInterface
{
    public function send(array $message): PushDeliveryResult;
}
