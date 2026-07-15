<?php

namespace Tests\Feature;

use App\Http\Resources\FeeRefundResource;
use App\Http\Resources\LearnerDiscountResource;
use App\Models\User;
use App\Services\Finance\FinanceDiscountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceFeesPhaseTwoTest extends TestCase
{
    public function test_phase_two_schema_contains_lifecycle_and_accounting_invariants(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_15_050001_harden_finance_fees_phase_two.php'));
        foreach (['fee_discount_applications', 'maximum_discount', 'payment_plan_installments', 'outstanding_amount', 'refunded_amount', 'reversal_ledger_entry_id', 'carry_forward_ledger_id', 'finance_clearances', 'finance_clearance_certificates', 'notification_key'] as $invariant) {
            $this->assertStringContainsString($invariant, $source);
        }
    }

    public function test_percentage_above_one_hundred_is_rejected_before_persistence(): void
    {
        $user = new User;
        $user->forceFill(['id' => '00000000-0000-0000-0000-000000000001', 'school_id' => '00000000-0000-0000-0000-000000000002']);
        $this->expectException(ValidationException::class);
        app(FinanceDiscountService::class)->save($user, ['discount_name' => 'Invalid', 'discount_type' => 'percentage', 'discount_value' => '100.01']);
    }

    public function test_zero_fixed_discount_is_rejected(): void
    {
        $user = new User;
        $user->forceFill(['id' => '00000000-0000-0000-0000-000000000001', 'school_id' => '00000000-0000-0000-0000-000000000002']);
        $this->expectException(ValidationException::class);
        app(FinanceDiscountService::class)->save($user, ['discount_name' => 'Invalid', 'discount_type' => 'fixed_amount', 'discount_value' => '0.00']);
    }

    public function test_phase_two_workflow_routes_use_specific_permissions(): void
    {
        $expected = ['api/finance/discounts/{discount}/approve' => 'permission:approve_fee_discounts', 'api/finance/invoices/{invoice}/discounts/{application}/reverse' => 'permission:reverse_fee_discounts', 'api/finance/payment-plans/{plan}/reschedule' => 'permission:reschedule_payment_plans', 'api/finance/refunds/{refund}/process' => 'permission:process_fee_refunds', 'api/finance/adjustments/{adjustment}/post' => 'permission:post_finance_adjustments', 'api/finance/arrears/{arrear}/carry-forward' => 'permission:carry_forward_fee_arrears', 'api/finance/learners/{learner}/clearance/override' => 'permission:override_fee_clearance', 'api/finance/analytics/aging' => 'permission:view_advanced_finance_analytics'];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($expected as $uri => $permission) {
            $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_portal_routes_have_benefit_only_permissions(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $learner = $routes->first(fn ($route) => $route->uri() === 'api/learner/fees/discounts');
        $parent = $routes->first(fn ($route) => $route->uri() === 'api/parent/learners/{learner}/fees/discounts');
        $this->assertContains('permission:view_own_fee_benefits', $learner->gatherMiddleware());
        $this->assertContains('permission:view_linked_learner_fee_benefits', $parent->gatherMiddleware());
    }

    public function test_legacy_phase_two_mutations_are_not_routed(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        foreach (['POST api/payment-plans', 'PUT api/payment-plans/{payment_plan}', 'DELETE api/payment-plans/{payment_plan}', 'POST api/fee-refunds', 'POST api/finance-adjustments', 'POST api/fee-arrears'] as $route) {
            $this->assertNotContains($route, $routes);
        }
    }

    public function test_portal_safe_resources_hide_private_and_approval_fields(): void
    {
        $assignment = (new LearnerDiscountResource((object) ['id' => '1', 'learner_id' => '2', 'discount_id' => '3', 'academic_year_id' => '4', 'term_id' => null, 'fee_category_id' => null, 'status' => 'active', 'assigned_value' => '10.00', 'starts_at' => null, 'ends_at' => null, 'private_notes' => 'secret', 'approved_by' => 'secret', 'created_at' => null]))->toArray(Request::create('/'));
        $refund = (new FeeRefundResource((object) ['id' => '1', 'learner_id' => '2', 'payment_id' => '3', 'refund_amount' => '10.00', 'reason' => 'Correction', 'status' => 'approved', 'refund_date' => null, 'requested_at' => null, 'approved_at' => null, 'processed_at' => null, 'approved_by' => 'secret']))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('private_notes', $assignment);
        $this->assertArrayNotHasKey('approved_by', $assignment);
        $this->assertArrayNotHasKey('approved_by', $refund);
    }

    public function test_finance_services_do_not_add_gateways_or_academic_result_writes(): void
    {
        $source = collect(glob(app_path('Services/Finance/*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
        foreach (['Mpesa', 'M-Pesa', 'Stripe', 'PayPal', 'exam_results', 'learning_area_results'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_append_only_and_maker_checker_controls_are_present(): void
    {
        $refund = file_get_contents(app_path('Services/Finance/FinanceRefundService.php'));
        $adjustment = file_get_contents(app_path('Services/Finance/FinanceAdjustmentService.php'));
        $notification = file_get_contents(app_path('Services/Finance/FinanceNotificationService.php'));
        $this->assertStringContainsString('Requester cannot approve their own refund', $refund);
        $this->assertStringContainsString('maker cannot approve', $adjustment);
        $this->assertStringContainsString('insertOrIgnore', $notification);
        $this->assertStringNotContainsString("learner_fee_ledger')->delete", $refund.$adjustment);
    }
}
