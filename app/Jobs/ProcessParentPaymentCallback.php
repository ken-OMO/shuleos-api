<?php

namespace App\Jobs;

use App\Services\ParentPortal\ParentPaymentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessParentPaymentCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $eventId, public array $callback) {}

    public function handle(ParentPaymentProcessingService $service): void
    {
        $event = DB::table('parent_payment_callback_events')->where('id', $this->eventId)->first();
        if (! $event || $event->processed_at || ! $event->payment_attempt_id) {
            return;
        }
        $service->process($event->payment_attempt_id, $this->callback);
        DB::table('parent_payment_callback_events')->where('id', $this->eventId)->update(['status' => 'processed', 'processed_at' => now()]);
    }
}
