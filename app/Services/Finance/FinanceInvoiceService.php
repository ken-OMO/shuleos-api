<?php

namespace App\Services\Finance;

use App\Models\FeeInvoice;
use App\Models\FeeInvoiceItem;
use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceInvoiceService
{
    public function __construct(private FinanceMoney $money, private FinanceAccountService $accounts, private FinanceLedgerService $ledger, private FinanceAuditService $audit) {}

    public function generate(User $user, string $learnerId, string $yearId, string $termId): FeeInvoice
    {
        return DB::transaction(function () use ($user, $learnerId, $yearId, $termId) {
            $learner = DB::table('learners')->where('id', $learnerId)->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('terms')->where('id', $termId)->where('school_id', $user->school_id)->where('academic_year_id', $yearId)->exists(), 422, 'Invalid year and term scope.');
            $existing = FeeInvoice::where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('academic_year_id', $yearId)->where('term_id', $termId)->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $structures = DB::table('fee_structures')->where('school_id', $user->school_id)->where('academic_year_id', $yearId)->where('term_id', $termId)->where('grade_id', $learner->grade_id)->where('status', 'active')->where('active', true)->where('is_deleted', false)->where(fn ($query) => $query->whereNull('stream_id')->orWhere('stream_id', $learner->stream_id))->orderByRaw('CASE WHEN stream_id IS NULL THEN 1 ELSE 0 END')->get()->unique('fee_category_id');
            if ($structures->isEmpty()) {
                throw ValidationException::withMessages(['structures' => 'No active applicable fee structures found.']);
            }
            $totalMinor = $structures->sum(fn ($structure) => $this->money->minor($this->money->positive($structure->amount)));
            $account = $this->accounts->provision($user, $learnerId);
            $invoice = FeeInvoice::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $learnerId, 'learner_fee_account_id' => $account->id, 'academic_year_id' => $yearId, 'term_id' => $termId, 'grade_id' => $learner->grade_id, 'stream_id' => $learner->stream_id, 'invoice_number' => 'INV-'.now()->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10)), 'total_amount' => $this->money->decimal($totalMinor), 'amount_paid' => '0.00', 'balance' => $this->money->decimal($totalMinor), 'status' => 'draft', 'invoice_date' => now()->toDateString(), 'due_date' => $structures->pluck('due_date')->filter()->sort()->first(), 'generated_by' => $user->id]);
            foreach ($structures as $structure) {
                FeeInvoiceItem::create(['id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'fee_category_id' => $structure->fee_category_id, 'amount' => $this->money->positive($structure->amount), 'notes' => $structure->notes, 'created_at' => now()]);
            }
            $this->audit->record($user, 'invoice_generated', 'fee_invoices', $invoice->id, [], ['total_amount' => $invoice->total_amount]);

            return $invoice->load('items');
        });
    }

    public function generateBulk(User $user, string $yearId, string $termId, ?string $gradeId = null, ?string $streamId = null): Collection
    {
        if ($gradeId && ! DB::table('grades')->where('id', $gradeId)->where('school_id', $user->school_id)->exists()) {
            throw ValidationException::withMessages(['grade_id' => 'Grade must belong to the authenticated school.']);
        }
        if ($streamId && ! DB::table('streams')->where('id', $streamId)->where('school_id', $user->school_id)->when($gradeId, fn ($query) => $query->where('grade_id', $gradeId))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream must belong to the authenticated school and selected grade.']);
        }

        return DB::transaction(function () use ($user, $yearId, $termId, $gradeId, $streamId) {
            return DB::table('learners')->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)
                ->when($gradeId, fn ($query) => $query->where('grade_id', $gradeId))
                ->when($streamId, fn ($query) => $query->where('stream_id', $streamId))
                ->orderBy('id')->pluck('id')->map(fn ($learnerId) => $this->generate($user, $learnerId, $yearId, $termId));
        });
    }

    public function post(User $user, string $id): FeeInvoice
    {
        return DB::transaction(function () use ($user, $id) {
            $invoice = FeeInvoice::whereKey($id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'draft') {
                return $invoice;
            }
            $itemMinor = $invoice->items()->get()->sum(fn ($item) => $this->money->minor($item->amount));
            $discountMinor = DB::table('fee_discount_applications')->where('invoice_id', $invoice->id)->where('status', 'active')->get()->sum(fn ($application) => $this->money->minor($application->discount_amount));
            if ($itemMinor <= 0 || $itemMinor - $discountMinor !== $this->money->minor($invoice->total_amount)) {
                throw ValidationException::withMessages(['invoice' => 'Invoice item total does not reconcile.']);
            }
            $account = LearnerFeeAccount::whereKey($invoice->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
            $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'invoice', 'reference_type' => 'fee_invoice', 'reference_id' => $invoice->id, 'debit' => $invoice->total_amount, 'description' => 'Posted invoice '.$invoice->invoice_number]);
            $invoice->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'invoice_posted', 'fee_invoices', $invoice->id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $invoice->learner_id, 'finance_invoice_posted', 'finance:invoice:'.$invoice->id.':posted', 'Fee invoice posted', 'A new fee invoice has been posted to your account.');

            return $invoice;
        });
    }

    public function cancel(User $user, string $id, string $reason): FeeInvoice
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $invoice = FeeInvoice::whereKey($id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            abort_if($invoice->allocations()->where('status', 'active')->exists(), 409, 'Allocated invoice cannot be cancelled without payment reversal.');
            abort_if($invoice->status === 'cancelled', 409, 'Invoice is already cancelled.');
            if ($invoice->status !== 'draft') {
                $account = LearnerFeeAccount::whereKey($invoice->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
                $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'invoice_cancellation', 'reference_type' => 'fee_invoice', 'reference_id' => $invoice->id, 'posting_action' => 'cancellation', 'credit' => $invoice->total_amount, 'description' => 'Cancellation of invoice '.$invoice->invoice_number]);
            }
            $invoice->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record($user, 'invoice_cancelled', 'fee_invoices', $invoice->id);

            return $invoice;
        });
    }
}
