<?php

namespace Tests\Feature;

use App\Http\Resources\FinanceSettingResource;
use App\Http\Resources\PaymentAllocationResource;
use App\Http\Resources\PaymentResource;
use App\Models\FinanceSetting;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Finance\FinanceMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinanceFeesPhaseOneTest extends TestCase
{
    public function test_money_arithmetic_uses_deterministic_minor_units(): void
    {
        $money = app(FinanceMoney::class);
        $this->assertSame(12345, $money->minor('123.45'));
        $this->assertSame('123.45', $money->decimal(12345));
        $this->assertSame('-0.01', $money->decimal(-1));
    }

    public function test_workflow_routes_have_specific_permissions(): void
    {
        $expected = ['api/finance/invoices/generate-bulk' => 'permission:generate_fee_invoices', 'api/finance/accounts/provision-bulk' => 'permission:provision_fee_accounts', 'api/finance/invoices/{invoice}/post' => 'permission:post_fee_invoices', 'api/finance/invoices/{invoice}/cancel' => 'permission:cancel_fee_invoices', 'api/finance/payments/{payment}/confirm' => 'permission:confirm_fee_payments', 'api/finance/payments/{payment}/reverse' => 'permission:reverse_fee_payments', 'api/finance/payments/{payment}/allocate' => 'permission:allocate_fee_payments', 'api/finance/ledger-integrity' => 'permission:reconcile_fee_ledger'];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($expected as $uri => $permission) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_legacy_finance_mutations_are_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        foreach (['POST api/fee-invoices', 'PUT api/fee-invoices/{fee_invoice}', 'POST api/payments', 'PUT api/payments/{payment}', 'POST api/payment-allocations', 'POST api/learner-fee-accounts'] as $route) {
            $this->assertNotContains($route, $routes);
        }
    }

    public function test_payment_resource_masks_phone_and_hides_actor_and_tenant_fields(): void
    {
        $data = (new PaymentResource(new Payment(['school_id' => 'secret', 'received_by' => 'secret-user', 'posted_by' => 'secret-user', 'payer_phone' => '0712345678'])))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('school_id', $data);
        $this->assertArrayNotHasKey('received_by', $data);
        $this->assertArrayNotHasKey('posted_by', $data);
        $this->assertSame('******5678', $data['payer_phone']);
    }

    public function test_settings_and_allocation_resources_hide_tenant_and_actor_fields(): void
    {
        $settings = (new FinanceSettingResource(new FinanceSetting(['school_id' => 'secret', 'currency' => 'KES'])))->toArray(Request::create('/'));
        $allocation = (new PaymentAllocationResource(new PaymentAllocation(['school_id' => 'secret', 'created_by' => 'secret-user', 'ledger_entry_id' => 'secret-ledger'])))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('school_id', $settings);
        $this->assertArrayNotHasKey('school_id', $allocation);
        $this->assertArrayNotHasKey('created_by', $allocation);
        $this->assertArrayNotHasKey('ledger_entry_id', $allocation);
    }

    public function test_ledger_is_append_only_and_reference_idempotent(): void
    {
        $source = file_get_contents(app_path('Services/Finance/FinanceLedgerService.php'));
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString("where('posting_action'", $source);
        $this->assertStringNotContainsString('->delete()', $source);
        $this->assertStringNotContainsString('learner_fee_ledger\')->update', $source);
    }

    public function test_no_gateway_or_academic_result_writes_were_added(): void
    {
        $source = collect(glob(app_path('Services/Finance/*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
        foreach (['Mpesa', 'M-Pesa', 'Stripe', 'PayPal', 'exam_results', 'learning_area_results'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_phase_one_forward_migration_contains_account_ledger_and_posting_invariants(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_14_040001_harden_finance_fees_phase_one.php'));
        foreach (['learner_fee_account_id', 'posting_action', 'cancellation_reason', 'confirmed_by', 'ledger_entry_id'] as $field) {
            $this->assertStringContainsString($field, $source);
        }
    }
}
