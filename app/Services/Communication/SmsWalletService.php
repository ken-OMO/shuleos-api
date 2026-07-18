<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SmsWalletService
{
    public function wallet(string $schoolId): object
    {
        DB::table('school_sms_wallets')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'balance_credits' => 0, 'low_balance_threshold' => 0, 'status' => 'active', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('school_sms_wallets')->where('school_id', $schoolId)->firstOrFail();
    }

    public function rateCard(string $route = 'transactional'): object
    {
        $rate = DB::table('sms_rate_cards')->where('provider', config('communication.sms.provider'))->where('country_code', 'KE')->where('route', $route)->where('active', true)->where('effective_from', '<=', now())->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))->orderByDesc('effective_from')->first();
        if (! $rate) {
            throw ValidationException::withMessages(['sms' => 'No active server-side SMS rate card is available.']);
        }

        return $rate;
    }

    public function reserve(string $schoolId, string $communicationId, string $deliveryId, int $segments, string $route = 'transactional'): object
    {
        return DB::transaction(function () use ($schoolId, $communicationId, $deliveryId, $segments, $route) {
            $existing = DB::table('sms_usage_records')->where('delivery_id', $deliveryId)->first();
            if ($existing) {
                return $existing;
            }
            $rate = $this->rateCard($route);
            $credits = $segments * $rate->credits_per_segment;
            $wallet = DB::table('school_sms_wallets')->where('school_id', $schoolId)->lockForUpdate()->first();
            abort_unless($wallet && $wallet->status === 'active', 422, 'SMS wallet is unavailable.');
            abort_if($wallet->balance_credits < $credits, 422, 'Insufficient SMS credits.');
            $balance = $wallet->balance_credits - $credits;
            DB::table('school_sms_wallets')->where('id', $wallet->id)->update(['balance_credits' => $balance, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('sms_credit_transactions')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'wallet_id' => $wallet->id, 'transaction_type' => 'usage', 'credits_delta' => -$credits, 'balance_after' => $balance, 'reference' => 'sms-reserve:'.$deliveryId, 'communication_id' => $communicationId, 'delivery_id' => $deliveryId, 'actor_user_id' => null, 'reason' => 'SMS credit reservation', 'created_at' => now()]);
            DB::table('sms_usage_records')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'communication_id' => $communicationId, 'delivery_id' => $deliveryId, 'rate_card_id' => $rate->id, 'rate_card_version' => $rate->version, 'segment_count' => $segments, 'credits_reserved' => $credits, 'credits_consumed' => 0, 'cost_minor' => $segments * $rate->cost_minor_per_segment, 'status' => 'reserved', 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('sms_usage_records')->where('delivery_id', $deliveryId)->first();
        });
    }

    public function consume(string $deliveryId): void
    {
        DB::table('sms_usage_records')->where('delivery_id', $deliveryId)->where('status', 'reserved')->update(['status' => 'consumed', 'credits_consumed' => DB::raw('credits_reserved'), 'consumed_at' => now(), 'updated_at' => now()]);
    }

    public function refund(string $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId) {
            $usage = DB::table('sms_usage_records')->where('delivery_id', $deliveryId)->where('status', 'reserved')->lockForUpdate()->first();
            if (! $usage) {
                return;
            }
            $wallet = DB::table('school_sms_wallets')->where('school_id', $usage->school_id)->lockForUpdate()->firstOrFail();
            $balance = $wallet->balance_credits + $usage->credits_reserved;
            DB::table('school_sms_wallets')->where('id', $wallet->id)->update(['balance_credits' => $balance, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('sms_credit_transactions')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $usage->school_id, 'wallet_id' => $wallet->id, 'transaction_type' => 'refund', 'credits_delta' => $usage->credits_reserved, 'balance_after' => $balance, 'reference' => 'sms-refund:'.$deliveryId, 'communication_id' => $usage->communication_id, 'delivery_id' => $deliveryId, 'actor_user_id' => null, 'reason' => 'Rejected provider submission', 'created_at' => now()]);
            DB::table('sms_usage_records')->where('id', $usage->id)->update(['status' => 'refunded', 'refunded_at' => now(), 'updated_at' => now()]);
        });
    }

    public function adjust(User $actor, int $credits, string $reason): object
    {
        abort_if($credits === 0 || trim($reason) === '', 422);

        return DB::transaction(function () use ($actor, $credits, $reason) {
            $wallet = DB::table('school_sms_wallets')->where('school_id', $actor->school_id)->lockForUpdate()->firstOrFail();
            $balance = $wallet->balance_credits + $credits;
            abort_if($balance < 0, 422, 'Adjustment would create a negative wallet balance.');
            DB::table('school_sms_wallets')->where('id', $wallet->id)->update(['balance_credits' => $balance, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('sms_credit_transactions')->insert(['id' => (string) Str::uuid(), 'school_id' => $actor->school_id, 'wallet_id' => $wallet->id, 'transaction_type' => 'adjustment', 'credits_delta' => $credits, 'balance_after' => $balance, 'reference' => 'manual:'.Str::uuid(), 'actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => now()]);

            return DB::table('school_sms_wallets')->where('id', $wallet->id)->first();
        });
    }
}
