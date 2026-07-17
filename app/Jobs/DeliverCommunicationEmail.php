<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverCommunicationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::table('communication_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['queued', 'failed'])->first();
        if (! $delivery) {
            return;
        }
        $communication = DB::table('communications')->where('id', $delivery->communication_id)->where('school_id', $delivery->school_id)->whereIn('status', ['queued', 'sending', 'sent', 'partially_failed'])->first();
        $recipient = DB::table('communication_recipient_snapshots')->where('communication_id', $delivery->communication_id)->where('user_id', $delivery->recipient_user_id)->where('school_id', $delivery->school_id)->first();
        if (! $communication || ! $recipient || ! $recipient->email_valid) {
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'skipped', 'failure_reason' => 'Email unavailable.', 'updated_at' => now()]);

            return;
        }
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'sending', 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        try {
            Mail::raw($communication->body, function ($message) use ($communication, $recipient) {
                $message->to($recipient->email)->subject($communication->subject);
            });
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
            DB::table('communications')->where('id', $communication->id)->where('status', 'sent')->update(['status' => 'partially_failed', 'updated_at' => now()]);
            throw $exception;
        }
    }
}
