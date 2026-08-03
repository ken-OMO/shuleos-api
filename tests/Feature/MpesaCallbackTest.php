<?php

namespace Tests\Feature;

use App\Services\ParentPortal\Providers\MpesaPaymentProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MpesaCallbackTest extends TestCase
{
    public function test_adapter_parses_provider_callback_into_safe_minor_units(): void
    {
        $result = (new MpesaPaymentProvider)->parseCallback(['Body' => ['stkCallback' => ['CheckoutRequestID' => 'checkout-1', 'ResultCode' => 0, 'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 125.50], ['Name' => 'MpesaReceiptNumber', 'Value' => 'RCP123']]]]]]);
        $this->assertTrue($result->successful);
        $this->assertSame(12550, $result->amountMinor);
        $this->assertSame('RCP123', $result->receipt);
    }

    public function test_callback_route_is_rate_limited_and_not_jwt_authenticated(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'api/webhooks/payments/mpesa');
        $this->assertNotContains('jwt', $route->gatherMiddleware());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }
}
