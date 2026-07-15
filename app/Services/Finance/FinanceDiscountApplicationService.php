<?php

namespace App\Services\Finance;

use App\Models\FeeInvoice;
use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceDiscountApplicationService
{
    public function __construct(private FinanceMoney $money, private FinanceLedgerService $ledger, private FinanceAuditService $audit) {}

    public function apply(User $user, string $invoiceId): Collection
    {
        return DB::transaction(function () use ($user, $invoiceId) {
            $invoice = FeeInvoice::whereKey($invoiceId)->where('school_id', $user->school_id)->whereIn('status', ['draft', 'posted', 'partially_paid'])->lockForUpdate()->firstOrFail();
            $assignments = DB::table('learner_discounts as assignment')->join('fee_discounts as discount', 'discount.id', '=', 'assignment.discount_id')->where('assignment.school_id', $user->school_id)->where('assignment.learner_id', $invoice->learner_id)->where('assignment.academic_year_id', $invoice->academic_year_id)->where('assignment.status', 'active')->where('assignment.active', true)->where('discount.status', 'active')->where('discount.active', true)->where(fn ($query) => $query->whereNull('assignment.term_id')->orWhere('assignment.term_id', $invoice->term_id))->where(fn ($query) => $query->whereNull('assignment.starts_at')->orWhere('assignment.starts_at', '<=', now()->toDateString()))->where(fn ($query) => $query->whereNull('assignment.ends_at')->orWhere('assignment.ends_at', '>=', now()->toDateString()))->select('assignment.*', 'discount.discount_type', 'discount.maximum_discount')->orderBy('assignment.created_at')->get();
            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages(['discounts' => 'No eligible active learner discounts found.']);
            }
            $grossItems = DB::table('fee_invoice_items')->where('invoice_id', $invoice->id)->get();
            $existingMinor = DB::table('fee_discount_applications')->where('invoice_id', $invoice->id)->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->discount_amount));
            $applications = collect();
            foreach ($assignments as $assignment) {
                $existing = DB::table('fee_discount_applications')->where('invoice_id', $invoice->id)->where('learner_discount_id', $assignment->id)->first();
                if ($existing) {
                    $applications->push($existing);

                    continue;
                }
                $allowedCategories = DB::table('fee_discount_categories')->where('discount_id', $assignment->discount_id)->pluck('fee_category_id');
                $eligibleItems = $grossItems->when($assignment->fee_category_id, fn ($items, $category) => $items->where('fee_category_id', $category))->when($allowedCategories->isNotEmpty(), fn ($items) => $items->whereIn('fee_category_id', $allowedCategories));
                $eligibleMinor = $eligibleItems->sum(fn ($item) => $this->money->minor($item->amount));
                if ($eligibleMinor <= 0) {
                    continue;
                }
                $valueMinor = $this->money->minor($assignment->assigned_value);
                $amountMinor = in_array($assignment->discount_type, ['percentage', 'full_waiver'], true) ? intdiv($eligibleMinor * $valueMinor, 10000) : $valueMinor;
                if ($assignment->maximum_discount) {
                    $amountMinor = min($amountMinor, $this->money->minor($assignment->maximum_discount));
                }
                $grossMinor = $grossItems->sum(fn ($item) => $this->money->minor($item->amount));
                $amountMinor = min($amountMinor, $eligibleMinor, max(0, $grossMinor - $existingMinor - $this->money->minor($invoice->amount_paid)));
                if ($amountMinor <= 0) {
                    continue;
                }
                $id = (string) Str::uuid();
                $ledgerId = null;
                if ($invoice->status !== 'draft') {
                    $account = LearnerFeeAccount::whereKey($invoice->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
                    $ledgerId = $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'fee_discount', 'reference_type' => 'fee_discount_application', 'reference_id' => $id, 'credit' => $this->money->decimal($amountMinor), 'description' => 'Discount applied to '.$invoice->invoice_number]);
                }
                DB::table('fee_discount_applications')->insert(['id' => $id, 'school_id' => $user->school_id, 'invoice_id' => $invoice->id, 'learner_discount_id' => $assignment->id, 'discount_id' => $assignment->discount_id, 'eligible_amount' => $this->money->decimal($eligibleMinor), 'discount_amount' => $this->money->decimal($amountMinor), 'status' => 'active', 'ledger_entry_id' => $ledgerId, 'applied_by' => $user->id, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $existingMinor += $amountMinor;
                $this->audit->record($user, 'discount_applied', 'fee_discount_applications', $id, [], ['amount' => $this->money->decimal($amountMinor)]);
                $applications->push(DB::table('fee_discount_applications')->where('id', $id)->first());
            }
            if ($applications->isEmpty()) {
                throw ValidationException::withMessages(['discounts' => 'Eligible discounts produced no applicable credit.']);
            }
            $this->recalculateInvoice($invoice);

            return $applications;
        });
    }

    public function reverse(User $user, string $invoiceId, string $applicationId, string $reason): object
    {
        return DB::transaction(function () use ($user, $invoiceId, $applicationId, $reason) {
            $invoice = FeeInvoice::whereKey($invoiceId)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            $application = DB::table('fee_discount_applications')->where('id', $applicationId)->where('school_id', $user->school_id)->where('invoice_id', $invoice->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($application, 404);
            if ($invoice->status !== 'draft') {
                $account = LearnerFeeAccount::whereKey($invoice->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
                $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'fee_discount_reversal', 'reference_type' => 'fee_discount_application', 'reference_id' => $application->id, 'posting_action' => 'reversal', 'debit' => $application->discount_amount, 'reverses_ledger_id' => $application->ledger_entry_id, 'description' => 'Discount reversal for '.$invoice->invoice_number]);
            }
            DB::table('fee_discount_applications')->where('id', $application->id)->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'updated_at' => now()]);
            $this->recalculateInvoice($invoice);
            $this->audit->record($user, 'discount_reversed', 'fee_discount_applications', $application->id);

            return DB::table('fee_discount_applications')->where('id', $application->id)->first();
        });
    }

    public function recalculateInvoice(FeeInvoice $invoice): void
    {
        $gross = DB::table('fee_invoice_items')->where('invoice_id', $invoice->id)->get()->sum(fn ($item) => $this->money->minor($item->amount));
        $discounts = DB::table('fee_discount_applications')->where('invoice_id', $invoice->id)->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->discount_amount));
        $paid = $this->money->minor($invoice->amount_paid);
        $total = max(0, $gross - $discounts);
        $balance = max(0, $total - $paid);
        $status = $invoice->status;
        if ($status !== 'draft') {
            $status = $balance === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'posted');
        }
        $invoice->update(['total_amount' => $this->money->decimal($total), 'balance' => $this->money->decimal($balance), 'status' => $status, 'updated_at' => now()]);
    }
}
