<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AppliedDiscountResource;
use App\Http\Resources\FeeArrearResource;
use App\Http\Resources\FeeDiscountResource;
use App\Http\Resources\FeeRefundResource;
use App\Http\Resources\FinanceAdjustmentResource;
use App\Http\Resources\FinanceClearanceResource;
use App\Http\Resources\FinancePaymentPlanResource;
use App\Http\Resources\LearnerDiscountResource;
use App\Services\Finance\FinanceAdjustmentService;
use App\Services\Finance\FinanceArrearsService;
use App\Services\Finance\FinanceClearanceService;
use App\Services\Finance\FinanceDiscountApplicationService;
use App\Services\Finance\FinanceDiscountService;
use App\Services\Finance\FinanceLearnerDiscountService;
use App\Services\Finance\FinancePaymentPlanService;
use App\Services\Finance\FinancePhaseTwoAnalyticsService;
use App\Services\Finance\FinanceRefundService;
use App\Services\Finance\FinanceStatementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancePhaseTwoController extends BaseApiController
{
    public function __construct(private FinanceDiscountService $discounts, private FinanceLearnerDiscountService $learnerDiscounts, private FinanceDiscountApplicationService $applications, private FinancePaymentPlanService $plans, private FinanceRefundService $refunds, private FinanceAdjustmentService $adjustments, private FinanceArrearsService $arrears, private FinanceStatementService $statements, private FinanceClearanceService $clearance, private FinancePhaseTwoAnalyticsService $analytics) {}

    public function discounts()
    {
        $rows = DB::table('fee_discounts')->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->orderBy('discount_name')->paginate(30);

        return $this->success(FeeDiscountResource::collection($rows));
    }

    public function discount(string $discount)
    {
        return $this->success(new FeeDiscountResource($this->discounts->find(auth()->user(), $discount)));
    }

    public function saveDiscount(Request $request, ?string $discount = null)
    {
        $data = $request->validate(['discount_name' => 'required|string|max:255', 'description' => 'nullable|string|max:4000', 'discount_type' => 'required|string', 'discount_value' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'maximum_discount' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'grade_id' => 'nullable|uuid', 'stream_id' => 'nullable|uuid', 'academic_year_id' => 'nullable|uuid', 'term_id' => 'nullable|uuid', 'effective_from' => 'nullable|date', 'effective_to' => 'nullable|date', 'fee_category_ids' => 'sometimes|array', 'fee_category_ids.*' => 'uuid']);

        return $this->success(new FeeDiscountResource($this->discounts->save(auth()->user(), $data, $discount)));
    }

    public function discountTransition(string $discount, string $status)
    {
        return $this->success(new FeeDiscountResource($this->discounts->transition(auth()->user(), $discount, $status)));
    }

    public function learnerDiscounts()
    {
        return $this->success(LearnerDiscountResource::collection(DB::table('learner_discounts')->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->latest('created_at')->paginate(30)));
    }

    public function learnerDiscount(string $assignment)
    {
        return $this->success(new LearnerDiscountResource($this->learnerDiscounts->find(auth()->user(), $assignment)));
    }

    public function assignDiscount(Request $request)
    {
        $data = $request->validate(['learner_id' => 'required|uuid', 'discount_id' => 'required|uuid', 'academic_year_id' => 'required|uuid', 'term_id' => 'nullable|uuid', 'fee_category_id' => 'nullable|uuid', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'assigned_value' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'override_reason' => 'nullable|string|max:4000', 'private_notes' => 'nullable|string|max:4000']);

        return $this->created(new LearnerDiscountResource($this->learnerDiscounts->assign(auth()->user(), $data)));
    }

    public function learnerDiscountAction(Request $request, string $assignment, string $action)
    {
        $row = $action === 'approve' ? $this->learnerDiscounts->approve(auth()->user(), $assignment) : $this->learnerDiscounts->cancel(auth()->user(), $assignment, $request->validate(['reason' => 'required|string|max:4000'])['reason']);

        return $this->success(new LearnerDiscountResource($row));
    }

    public function applyDiscounts(string $invoice)
    {
        return $this->success(AppliedDiscountResource::collection($this->applications->apply(auth()->user(), $invoice)));
    }

    public function invoiceDiscounts(string $invoice)
    {
        abort_unless(DB::table('fee_invoices')->where('id', $invoice)->where('school_id', auth()->user()->school_id)->exists(), 404);

        return $this->success(AppliedDiscountResource::collection(DB::table('fee_discount_applications')->where('school_id', auth()->user()->school_id)->where('invoice_id', $invoice)->get()));
    }

    public function reverseDiscount(Request $request, string $invoice, string $application)
    {
        return $this->success(new AppliedDiscountResource($this->applications->reverse(auth()->user(), $invoice, $application, $request->validate(['reason' => 'required|string|max:4000'])['reason'])));
    }

    public function paymentPlans()
    {
        return $this->success(FinancePaymentPlanResource::collection(DB::table('payment_plans')->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->latest('created_at')->paginate(30)));
    }

    public function paymentPlan(string $plan)
    {
        return $this->success(new FinancePaymentPlanResource($this->plans->find(auth()->user(), $plan)));
    }

    public function savePaymentPlan(Request $request, ?string $plan = null)
    {
        $data = $request->validate(['learner_id' => 'required|uuid', 'academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'plan_name' => 'required|string|max:255', 'description' => 'nullable|string|max:4000', 'invoice_ids' => 'required|array|min:1', 'invoice_ids.*' => 'uuid', 'installments' => 'required|array|min:1', 'installments.*.name' => 'nullable|string|max:100', 'installments.*.amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'installments.*.due_date' => 'required|date', 'installments.*.installment_order' => 'required|integer|min:1']);

        return $this->success(new FinancePaymentPlanResource($this->plans->save(auth()->user(), $data, $plan)));
    }

    public function paymentPlanAction(Request $request, string $plan, string $action)
    {
        if ($action === 'reschedule') {
            $data = $request->validate(['due_dates' => 'required|array|min:1', 'due_dates.*' => 'date', 'reason' => 'required|string|max:4000']);
            $row = $this->plans->reschedule(auth()->user(), $plan, $data['due_dates'], $data['reason']);
        } else {
            $reason = $action === 'cancel' ? $request->validate(['reason' => 'required|string|max:4000'])['reason'] : null;
            $row = $this->plans->transition(auth()->user(), $plan, $action === 'approve' ? 'approved' : ($action === 'activate' ? 'active' : 'cancelled'), $reason);
        }

        return $this->success(new FinancePaymentPlanResource($row));
    }

    public function refunds()
    {
        return $this->success(FeeRefundResource::collection(DB::table('fee_refunds')->where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(30)));
    }

    public function refund(string $refund)
    {
        return $this->success(new FeeRefundResource($this->refunds->find(auth()->user(), $refund)));
    }

    public function requestRefund(Request $request)
    {
        $data = $request->validate(['payment_id' => 'required|uuid', 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'reason' => 'required|string|max:4000']);

        return $this->created(new FeeRefundResource($this->refunds->request(auth()->user(), $data)));
    }

    public function refundAction(Request $request, string $refund, string $action)
    {
        $reason = in_array($action, ['reject', 'cancel'], true) ? $request->validate(['reason' => 'required|string|max:4000'])['reason'] : null;
        $row = match ($action) {
            'approve' => $this->refunds->decide(auth()->user(), $refund, 'approved'), 'reject' => $this->refunds->decide(auth()->user(), $refund, 'rejected', $reason), 'process' => $this->refunds->process(auth()->user(), $refund), default => $this->refunds->cancel(auth()->user(), $refund, $reason)
        };

        return $this->success(new FeeRefundResource($row));
    }

    public function adjustments()
    {
        return $this->success(FinanceAdjustmentResource::collection(DB::table('finance_adjustments')->where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(30)));
    }

    public function adjustment(string $adjustment)
    {
        return $this->success(new FinanceAdjustmentResource($this->adjustments->find(auth()->user(), $adjustment)));
    }

    public function createAdjustment(Request $request)
    {
        $data = $request->validate(['learner_fee_account_id' => 'required|uuid', 'adjustment_type' => 'required|string', 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'reason' => 'required|string|max:4000', 'academic_year_id' => 'nullable|uuid', 'term_id' => 'nullable|uuid', 'reference_type' => 'nullable|string|max:100', 'reference_id' => 'nullable|uuid']);
        if ($data['adjustment_type'] === 'write_off') {
            $allowed = $this->authContext->hasPermission(
                auth()->user(),
                'write_off_fee_balances'
            );
            abort_unless($allowed, 403, 'Write-off permission is required.');
        }

        return $this->created(new FinanceAdjustmentResource($this->adjustments->create(auth()->user(), $data)));
    }

    public function adjustmentAction(Request $request, string $adjustment, string $action)
    {
        $reason = in_array($action, ['reject', 'reverse'], true) ? $request->validate(['reason' => 'required|string|max:4000'])['reason'] : null;

        return $this->success(new FinanceAdjustmentResource($this->adjustments->transition(auth()->user(), $adjustment, match ($action) {
            'submit' => 'submitted', 'approve' => 'approved', 'post' => 'posted', 'reverse' => 'reversed', default => 'rejected'
        }, $reason)));
    }

    public function calculateArrears(Request $request)
    {
        $data = $request->validate(['academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'learner_id' => 'nullable|uuid']);

        return $this->success(['calculated' => $this->arrears->calculate(auth()->user(), $data['academic_year_id'], $data['term_id'], $data['learner_id'] ?? null)]);
    }

    public function arrears()
    {
        return $this->success(FeeArrearResource::collection(DB::table('fee_arrears')->where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(30)));
    }

    public function arrear(string $arrear)
    {
        return $this->success(new FeeArrearResource($this->arrears->find(auth()->user(), $arrear)));
    }

    public function arrearAction(Request $request, string $arrear, string $action)
    {
        if ($action === 'carry-forward') {
            $data = $request->validate(['academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'amount' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/']]);
            $row = $this->arrears->carryForward(auth()->user(), $arrear, $data['academic_year_id'], $data['term_id'], $data['amount'] ?? null);
        } else {
            $row = $this->arrears->resolve(auth()->user(), $arrear);
        }

        return $this->success(new FeeArrearResource($row));
    }

    public function statement(Request $request, string $account)
    {
        return $this->success($this->statements->statement(auth()->user(), $account, $request->validate(['academic_year_id' => 'nullable|uuid', 'term_id' => 'nullable|uuid', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date', 'transaction_type' => 'nullable|string|max:100'])));
    }

    public function clearance(string $learner)
    {
        return $this->success(new FinanceClearanceResource($this->clearance->status(auth()->user(), $learner)));
    }

    public function clearanceOverride(Request $request, string $learner)
    {
        $data = $request->validate(['status' => 'required|in:conditionally_cleared,cleared', 'reason' => 'required|string|max:4000', 'expires_at' => 'required|date']);

        return $this->success(new FinanceClearanceResource($this->clearance->override(auth()->user(), $learner, $data)));
    }

    public function clearanceRevoke(Request $request, string $learner)
    {
        return $this->success(new FinanceClearanceResource($this->clearance->revoke(auth()->user(), $learner, $request->validate(['reason' => 'required|string|max:4000'])['reason'])));
    }

    public function certificate(string $certificate)
    {
        $row = DB::table('finance_clearance_certificates')->where('certificate_number', $certificate)->where('school_id', auth()->user()->school_id)->select('certificate_number', 'learner_id', 'issued_at', 'expires_at', 'revoked_at')->first();
        abort_unless($row, 404);

        return $this->success($row);
    }

    public function analytics()
    {
        return $this->success($this->analytics->summary(auth()->user()));
    }
}
