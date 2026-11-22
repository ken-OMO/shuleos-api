<?php

namespace App\Services\School;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class SubscriptionDarajaSandbox
{
    private const HOST = 'https://sandbox.safaricom.co.ke';

    private const SHORTCODE = '174379';

    public function initiate(string $phone, int $amount, string $reference): array
    {
        $callback = config('subscription_daraja.callback_url');
        if (! is_string($callback) || ! filter_var($callback, FILTER_VALIDATE_URL)
            || parse_url($callback, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Configure an HTTPS sandbox callback URL.');
        }

        return $this->post('/mpesa/stkpush/v1/processrequest', [
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => self::SHORTCODE,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callback,
            'AccountReference' => $reference,
            'TransactionDesc' => 'School trial test',
        ]);
    }

    public function query(string $checkoutId): array
    {
        return $this->post('/mpesa/stkpushquery/v1/query', [
            'CheckoutRequestID' => $checkoutId,
        ]);
    }

    private function post(string $path, array $payload): array
    {
        $key = config('subscription_daraja.key');
        $secret = config('subscription_daraja.secret');
        $passkey = config('subscription_daraja.passkey');
        if (blank($key) || blank($secret) || blank($passkey)) {
            throw new RuntimeException('Configure the Daraja sandbox credentials.');
        }

        try {
            $client = Http::baseUrl(self::HOST)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15);
            $tokenResponse = (clone $client)->withBasicAuth($key, $secret)
                ->get('/oauth/v1/generate', ['grant_type' => 'client_credentials'])->throw();
            $token = $tokenResponse->json('access_token');
            if (! $tokenResponse->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException;
            }

            $timestamp = now('Africa/Nairobi')->format('YmdHis');
            $response = $client->withToken($token)->post($path, $payload + [
                'BusinessShortCode' => self::SHORTCODE,
                'Password' => base64_encode(self::SHORTCODE.$passkey.$timestamp),
                'Timestamp' => $timestamp,
            ])->throw();
            $result = $response->json();
            if (! $response->successful() || ! is_array($result)) {
                throw new RuntimeException;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Daraja sandbox request could not be confirmed.');
        }
    }
}
