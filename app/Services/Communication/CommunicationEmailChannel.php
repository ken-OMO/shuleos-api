<?php

namespace App\Services\Communication;

use App\Jobs\DeliverCommunicationEmail;

class CommunicationEmailChannel
{
    public function queue(string $deliveryId): void
    {
        DeliverCommunicationEmail::dispatch($deliveryId)->afterCommit();
    }
}
