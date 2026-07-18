<?php

namespace App\Services\Communication;

use App\Contracts\Communication\EmailProviderInterface;
use App\Contracts\Communication\SmsProviderInterface;

class PhaseTwoCommunicationPreviewService
{
    public function __construct(private SmsSegmentCalculator $segments, private SmsWalletService $wallet, private EmailProviderInterface $email, private SmsProviderInterface $sms) {}

    public function extend(string $schoolId, array $resolution, string $body, array $channels): array
    {
        $smsRequested = in_array('sms', $channels, true);
        $segment = $smsRequested ? $this->segments->calculate($body) : ['segments' => 0, 'encoding' => null];
        $expectedSegments = $resolution['sms_eligible'] * $segment['segments'];
        $wallet = $smsRequested ? $this->wallet->wallet($schoolId) : null;
        $rate = $smsRequested ? $this->wallet->rateCard() : null;
        $credits = $rate ? $expectedSegments * $rate->credits_per_segment : 0;

        return [
            'suppressed_email_count' => $resolution['excluded']['suppressed_email'] ?? 0,
            'sms_eligible_count' => $resolution['sms_eligible'] ?? 0,
            'invalid_phone_count' => $resolution['excluded']['invalid_phone'] ?? 0,
            'opted_out_count' => $resolution['excluded']['opted_out'] ?? 0,
            'expected_sms_segments' => $expectedSegments,
            'estimated_sms_credits' => $credits,
            'current_wallet_balance' => $wallet?->balance_credits,
            'projected_wallet_balance' => $wallet ? $wallet->balance_credits - $credits : null,
            'provider_readiness' => ['email' => $this->email->healthy(), 'sms' => $smsRequested ? $this->sms->healthy() : null],
            'blocked_channel_reasons' => $smsRequested && $wallet && $wallet->balance_credits < $credits ? ['Insufficient SMS credits.'] : [],
            'rate_card_version' => $rate?->version,
            'sms_encoding' => $segment['encoding'],
        ];
    }
}
