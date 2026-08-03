<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Communication\ProviderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CommunicationWebhookController extends Controller
{
    public function __construct(private ProviderWebhookService $webhooks) {}

    public function resend(Request $request): Response
    {
        $this->webhooks->handleResend($request);

        return response()->noContent();
    }

    public function africasTalking(Request $request): Response
    {
        $this->webhooks->handleAfricasTalking($request);

        return response()->noContent();
    }
}
