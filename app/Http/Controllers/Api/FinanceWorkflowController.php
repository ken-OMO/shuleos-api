<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\FeeCategoryResource;
use App\Http\Resources\FeeInvoiceResource;
use App\Http\Resources\FeeStructureResource;
use App\Http\Resources\FinanceSettingResource;
use App\Http\Resources\LearnerFeeAccountResource;
use App\Http\Resources\PaymentAllocationResource;
use App\Http\Resources\PaymentResource;
use App\Models\FeeCategory;
use App\Models\FeeInvoice;
use App\Models\FeeStructure;
use App\Models\LearnerFeeAccount;
use App\Models\Payment;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\FinanceInvoiceService;
use App\Services\Finance\FinanceMasterDataService;
use App\Services\Finance\FinancePaymentService;
use App\Services\Finance\FinanceReceiptService;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceWorkflowController extends BaseApiController
{
    public function __construct(private FinanceMasterDataService $master, private FinanceAccountService $accounts, private FinanceInvoiceService $invoices, private FinancePaymentService $payments, private FinanceReceiptService $receipts, private FinanceAnalyticsService $analytics, private FinanceSettingsService $settings) {}

    public function categories()
    {
        return $this->success(FeeCategoryResource::collection(FeeCategory::where('school_id', auth()->user()->school_id)->where('is_deleted', false)->orderBy('category_name')->get()));
    }

    public function category(string $category)
    {
        return $this->success(new FeeCategoryResource(FeeCategory::whereKey($category)->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->firstOrFail()));
    }

    public function saveCategory(Request $request, ?string $category = null)
    {
        return $this->success(new FeeCategoryResource($this->master->category(auth()->user(), $request->validate(['category_name' => 'required|string|max:255', 'description' => 'nullable|string|max:4000', 'active' => 'sometimes|boolean']), $category)));
    }

    public function deactivateCategory(string $category)
    {
        return $this->success(new FeeCategoryResource($this->master->deactivate(auth()->user(), $category)));
    }

    public function structures()
    {
        return $this->success(FeeStructureResource::collection(FeeStructure::where('school_id', auth()->user()->school_id)->where('is_deleted', false)->latest('created_at')->paginate(30)));
    }

    public function structure(string $structure)
    {
        return $this->success(new FeeStructureResource(FeeStructure::whereKey($structure)->where('school_id', auth()->user()->school_id)->where('is_deleted', false)->firstOrFail()));
    }

    public function saveStructure(Request $request, ?string $structure = null)
    {
        return $this->success(new FeeStructureResource($this->master->structure(auth()->user(), $request->validate(['academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'grade_id' => 'required|uuid', 'stream_id' => 'nullable|uuid', 'fee_category_id' => 'required|uuid', 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'due_date' => 'nullable|date', 'notes' => 'nullable|string|max:4000']), $structure)));
    }

    public function structureTransition(string $structure, string $status)
    {
        return $this->success(new FeeStructureResource($this->master->transition(auth()->user(), $structure, $status)));
    }

    public function accountProvision(Request $request)
    {
        return $this->created(new LearnerFeeAccountResource($this->accounts->provision(auth()->user(), $request->validate(['learner_id' => 'required|uuid'])['learner_id'])));
    }

    public function accountProvisionBulk(Request $request)
    {
        $data = $request->validate(['grade_id' => 'nullable|uuid', 'stream_id' => 'nullable|uuid']);

        return $this->created(LearnerFeeAccountResource::collection($this->accounts->provisionBulk(auth()->user(), $data['grade_id'] ?? null, $data['stream_id'] ?? null)));
    }

    public function accounts()
    {
        return $this->success(LearnerFeeAccountResource::collection(LearnerFeeAccount::where('school_id', auth()->user()->school_id)->paginate(30)));
    }

    public function account(string $account)
    {
        return $this->success(new LearnerFeeAccountResource(LearnerFeeAccount::whereKey($account)->where('school_id', auth()->user()->school_id)->firstOrFail()));
    }

    public function ledger(string $account)
    {
        $model = LearnerFeeAccount::whereKey($account)->where('school_id', auth()->user()->school_id)->firstOrFail();

        return $this->success(DB::table('learner_fee_ledger')->where('school_id', auth()->user()->school_id)->where('learner_fee_account_id', $model->id)->select('id', 'transaction_date', 'transaction_type', 'reference_type', 'debit_amount', 'credit_amount', 'running_balance', 'description', 'created_at')->orderBy('transaction_date')->orderBy('created_at')->orderBy('id')->get());
    }

    public function recalculate(string $account)
    {
        return $this->success(new LearnerFeeAccountResource($this->accounts->recalculate(auth()->user(), $account)));
    }

    public function generateInvoice(Request $request)
    {
        $data = $request->validate(['learner_id' => 'required|uuid', 'academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid']);

        return $this->created(new FeeInvoiceResource($this->invoices->generate(auth()->user(), $data['learner_id'], $data['academic_year_id'], $data['term_id'])));
    }

    public function generateInvoicesBulk(Request $request)
    {
        $data = $request->validate(['academic_year_id' => 'required|uuid', 'term_id' => 'required|uuid', 'grade_id' => 'nullable|uuid', 'stream_id' => 'nullable|uuid']);

        return $this->created(FeeInvoiceResource::collection($this->invoices->generateBulk(auth()->user(), $data['academic_year_id'], $data['term_id'], $data['grade_id'] ?? null, $data['stream_id'] ?? null)));
    }

    public function paymentMethods()
    {
        return $this->success(DB::table('payment_methods')->where('school_id', auth()->user()->school_id)->where('active', true)->where('is_online', false)->select('id', 'method_name', 'is_online', 'active')->orderBy('method_name')->get());
    }

    public function invoices()
    {
        return $this->success(FeeInvoiceResource::collection(FeeInvoice::where('school_id', auth()->user()->school_id)->with('items')->latest('created_at')->paginate(30)));
    }

    public function invoice(string $invoice)
    {
        return $this->success(new FeeInvoiceResource(FeeInvoice::whereKey($invoice)->where('school_id', auth()->user()->school_id)->with('items')->firstOrFail()));
    }

    public function postInvoice(string $invoice)
    {
        return $this->success(new FeeInvoiceResource($this->invoices->post(auth()->user(), $invoice)));
    }

    public function cancelInvoice(Request $request, string $invoice)
    {
        return $this->success(new FeeInvoiceResource($this->invoices->cancel(auth()->user(), $invoice, $request->validate(['reason' => 'required|string|max:4000'])['reason'])));
    }

    public function recordPayment(Request $request)
    {
        return $this->created(new PaymentResource($this->payments->create(auth()->user(), $request->validate(['learner_fee_account_id' => 'required|uuid', 'payment_method_id' => 'required|uuid', 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'transaction_reference' => 'nullable|string|max:255', 'payment_date' => 'required|date|before_or_equal:today', 'payer_phone' => 'nullable|string|max:30', 'payer_name' => 'nullable|string|max:255', 'remarks' => 'nullable|string|max:4000']))));
    }

    public function payments()
    {
        return $this->success(PaymentResource::collection(Payment::where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(30)));
    }

    public function payment(string $payment)
    {
        return $this->success(new PaymentResource(Payment::whereKey($payment)->where('school_id', auth()->user()->school_id)->firstOrFail()));
    }

    public function confirmPayment(string $payment)
    {
        return $this->success(new PaymentResource($this->payments->confirm(auth()->user(), $payment)));
    }

    public function reversePayment(Request $request, string $payment)
    {
        return $this->success(new PaymentResource($this->payments->reverse(auth()->user(), $payment, $request->validate(['reason' => 'required|string|max:4000'])['reason'])));
    }

    public function allocate(Request $request, string $payment)
    {
        $data = $request->validate(['invoice_id' => 'required|uuid', 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/']]);

        return $this->created(new PaymentAllocationResource($this->payments->allocate(auth()->user(), $payment, $data['invoice_id'], $data['amount'])));
    }

    public function autoAllocate(string $payment)
    {
        return $this->success(PaymentAllocationResource::collection($this->payments->autoAllocate(auth()->user(), $payment)));
    }

    public function allocations(string $payment)
    {
        Payment::whereKey($payment)->where('school_id', auth()->user()->school_id)->firstOrFail();

        return $this->success(DB::table('payment_allocations')->where('school_id', auth()->user()->school_id)->where('payment_id', $payment)->select('id', 'invoice_id', 'allocated_amount', 'status', 'created_at')->get());
    }

    public function receipt(string $payment)
    {
        return $this->success($this->receipts->receipt(auth()->user(), $payment));
    }

    public function receiptNumber(string $receipt)
    {
        $payment = Payment::where('school_id', auth()->user()->school_id)->where('receipt_number', $receipt)->firstOrFail();

        return $this->receipt($payment->id);
    }

    public function analytics()
    {
        return $this->success($this->analytics->summary(auth()->user()));
    }

    public function settings()
    {
        return $this->success(new FinanceSettingResource($this->settings->get(auth()->user())));
    }

    public function updateSettings(Request $request)
    {
        return $this->success(new FinanceSettingResource($this->settings->update(auth()->user(), $request->validate(['currency' => 'sometimes|string|size:3', 'allow_partial_payments' => 'sometimes|boolean', 'allow_overpayments' => 'sometimes|boolean', 'auto_generate_invoices' => 'sometimes|boolean', 'require_fee_clearance_for_results' => 'sometimes|boolean', 'require_fee_clearance_for_report_cards' => 'sometimes|boolean', 'require_fee_clearance_for_exams' => 'sometimes|boolean', 'clearance_threshold' => ['sometimes', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'reminder_due_soon_days' => 'sometimes|integer|min:0|max:90', 'finance_reminders_enabled' => 'sometimes|boolean']))));
    }
}
