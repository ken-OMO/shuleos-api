<?php

namespace App\Services\ParentPortal\Providers;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Services\ParentPortal\Payments\PaymentCallbackResult;
use App\Services\ParentPortal\Payments\PaymentInitiationResult;
use App\Services\ParentPortal\Payments\PaymentStatusResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MpesaPaymentProvider implements PaymentProviderInterface
{
    public function ready(): bool
    {
        return collect(['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'])
            ->every(fn ($key) => filled(config('parent_portal_phase_two.mpesa.'.$key)));
    }

    public function initiate(array $request): PaymentInitiationResult
    {
        if (! $this->ready()) {
            throw new RuntimeException('The configured payment provider is not ready.');
        }

        $token = $this->token();
        $timestamp = now()->format('YmdHis');
        $shortcode = config('parent_portal_phase_two.mpesa.shortcode');
        $response = Http::withToken($token)->acceptJson()
            ->timeout(config('parent_portal_phase_two.mpesa.timeout', 10))
            ->retry(config('parent_portal_phase_two.mpesa.retry_limit', 1), 250, throw: false)
            ->post($this->url('/mpesa/stkpush/v1/processrequest'), [
                'BusinessShortCode' => $shortcode,
                'Password' => base64_encode($shortcode.config('parent_portal_phase_two.mpesa.passkey').$timestamp),
                'Timestamp' => $timestamp,
                'TransactionType' => config('parent_portal_phase_two.mpesa.transaction_type', 'CustomerPayBillOnline'),
                'Amount' => intdiv($request['amount_minor'], 100),
                'PartyA' => $request['phone'],
                'PartyB' => $shortcode,
                'PhoneNumber' => $request['phone'],
                'CallBackURL' => config('parent_portal_phase_two.mpesa.callback_url'),
                'AccountReference' => $request['reference'],
                'TransactionDesc' => 'School fees',
            ]);
        $body = $response->json() ?: [];
        $accepted = $response->successful() && (string) ($body['ResponseCode'] ?? '') === '0';

        return new PaymentInitiationResult(
            $accepted,
            $body['RequestId'] ?? null,
            $body['CheckoutRequestID'] ?? null,
            $body['MerchantRequestID'] ?? null,
            $accepted ? null : 'provider_rejected',
            $accepted ? null : 'The payment request was not accepted. Please retry later.',
        );
    }

    public function parseCallback(array $payload): PaymentCallbackResult
    {
        $callback = data_get($payload, 'Body.stkCallback', []);
        $metadata = collect(data_get($callback, 'CallbackMetadata.Item', []))->mapWithKeys(fn ($item) => [($item['Name'] ?? '') => $item['Value'] ?? null]);
        $code = (int) ($callback['ResultCode'] ?? -1);
        $checkout = (string) ($callback['CheckoutRequestID'] ?? '');

        return new PaymentCallbackResult(
            hash('sha256', $checkout.'|'.$code.'|'.($metadata['MpesaReceiptNumber'] ?? '')),
            $checkout,
            $code === 0,
            isset($metadata['Amount']) ? (int) round(((float) $metadata['Amount']) * 100) : null,
            'KES',
            $metadata['MpesaReceiptNumber'] ?? null,
            $code === 0 ? null : $this->failureCode($code),
            $code === 0 ? null : 'The payment was not completed.',
        );
    }

    public function status(string $checkoutRequestId): PaymentStatusResult
    {
        if (! $this->ready()) {
            return new PaymentStatusResult('unavailable', 'provider_unavailable');
        }
        $timestamp = now()->format('YmdHis');
        $shortcode = config('parent_portal_phase_two.mpesa.shortcode');
        $response = Http::withToken($this->token())->acceptJson()->timeout(config('parent_portal_phase_two.mpesa.timeout', 10))
            ->retry(config('parent_portal_phase_two.mpesa.retry_limit', 1), 250, throw: false)
            ->post($this->url('/mpesa/stkpushquery/v1/query'), [
                'BusinessShortCode' => $shortcode,
                'Password' => base64_encode($shortcode.config('parent_portal_phase_two.mpesa.passkey').$timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);
        if (! $response->successful()) {
            return new PaymentStatusResult('unavailable', 'provider_unavailable');
        }
        $code = (int) $response->json('ResultCode', -1);

        return $code === 0 ? new PaymentStatusResult('successful') : new PaymentStatusResult('failed', $this->failureCode($code));
    }

    private function token(): string
    {
        $response = Http::withBasicAuth(config('parent_portal_phase_two.mpesa.consumer_key'), config('parent_portal_phase_two.mpesa.consumer_secret'))
            ->timeout(config('parent_portal_phase_two.mpesa.timeout', 10))->retry(1, 250, throw: false)
            ->get($this->url('/oauth/v1/generate'), ['grant_type' => 'client_credentials']);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('Payment provider authentication failed.');
        }

        return $token;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('parent_portal_phase_two.mpesa.base_url'), '/').$path;
    }

    private function failureCode(int $code): string
    {
        return match ($code) {
            1032 => 'customer_cancelled', 1 => 'insufficient_funds', 1037 => 'customer_unreachable', 2001 => 'invalid_pin', default => 'provider_failed',
        };
    }
}
