<?php

namespace Tests\Feature;

use App\Services\ParentPortal\Providers\FakePaymentProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParentPaymentTest extends TestCase
{
    public function test_fake_provider_is_deterministic_and_never_calls_network(): void
    {
        Http::preventStrayRequests();
        $provider = new FakePaymentProvider;
        $first = $provider->initiate(['idempotency_key' => 'same-key']);
        $second = $provider->initiate(['idempotency_key' => 'same-key']);

        $this->assertTrue($first->accepted);
        $this->assertSame($first->checkoutRequestId, $second->checkoutRequestId);
    }

    public function test_controller_never_posts_directly_to_finance_ledger(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ParentPortalPhaseTwoController.php'));
        $this->assertStringNotContainsString('FinanceLedgerService', $source);
        $this->assertStringNotContainsString('learner_fee_ledger', $source);
    }
}
