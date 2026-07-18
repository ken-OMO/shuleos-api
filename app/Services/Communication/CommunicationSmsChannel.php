<?php

namespace App\Services\Communication;

use App\Jobs\DeliverCommunicationSms;

class CommunicationSmsChannel
{
    public function queue(string $deliveryId): void
    {
        DeliverCommunicationSms::dispatch($deliveryId)->afterCommit();
    }
}
