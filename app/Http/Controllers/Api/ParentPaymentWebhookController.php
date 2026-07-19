<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ParentPortal\ParentPaymentCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentPaymentWebhookController extends Controller
{
    public function mpesa(Request $request, ParentPaymentCallbackService $callbacks): JsonResponse
    {
        $secret = (string) config('parent_portal_phase_two.callback_secret');
        abort_if($secret === '', 503, 'Callback is not configured.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Callback-Secret')), 401, 'Invalid callback authentication.');
        abort_if(strlen($request->getContent()) > config('parent_portal_phase_two.callback_max_bytes', 32768), 413, 'Callback is too large.');
        $payload = $request->json()->all();
        abort_unless(is_array($payload), 422, 'Invalid callback.');

        return response()->json(['accepted' => (bool) $callbacks->accept($payload)['accepted']], 202);
    }
}
