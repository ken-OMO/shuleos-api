<?php

namespace App\Services\Finance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinanceAnalyticsService
{
    public function __construct(private FinanceMoney $money) {}

    public function summary(User $user): array
    {
        $invoices = DB::table('fee_invoices')->where('school_id', $user->school_id)->where('status', '!=', 'cancelled')->get();
        $payments = DB::table('payments')->where('school_id', $user->school_id)->where('payment_status', 'confirmed')->where('reversed', false)->get();
        $invoiced = $invoices->sum(fn ($row) => $this->money->minor($row->total_amount));
        $collected = $payments->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00'));
        $summarizeInvoices = fn ($rows) => ['invoiced' => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->total_amount))), 'paid' => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->amount_paid))), 'outstanding' => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->balance)))];
        $ledgerMismatches = DB::table('learner_fee_accounts as account')->where('account.school_id', $user->school_id)->get()->filter(function ($account) use ($user) {
            $balance = DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('learner_fee_account_id', $account->id)->get()->sum(fn ($row) => $this->money->minor($row->debit_amount) - $this->money->minor($row->credit_amount));

            return $balance !== $this->money->minor($account->current_balance);
        })->count();
        $invoiceItemMismatches = $invoices->filter(function ($invoice) {
            $items = DB::table('fee_invoice_items')->where('invoice_id', $invoice->id)->get()->sum(fn ($item) => $this->money->minor($item->amount));

            return $items !== $this->money->minor($invoice->total_amount);
        })->count();
        $allocationMismatches = $invoices->filter(function ($invoice) use ($user) {
            $allocated = DB::table('payment_allocations')->where('school_id', $user->school_id)->where('invoice_id', $invoice->id)->where('status', 'active')->get()->sum(fn ($allocation) => $this->money->minor($allocation->allocated_amount) - $this->money->minor($allocation->refunded_amount ?? '0.00'));

            return $allocated !== $this->money->minor($invoice->amount_paid);
        })->count();
        $today = CarbonImmutable::today();
        $overdue = $invoices->filter(fn ($invoice) => $invoice->due_date && CarbonImmutable::parse($invoice->due_date)->isBefore($today) && $this->money->minor($invoice->balance) > 0);
        $aging = collect(['current' => 0, '1_30_days' => 0, '31_60_days' => 0, '61_90_days' => 0, 'over_90_days' => 0]);
        foreach ($invoices->filter(fn ($invoice) => $this->money->minor($invoice->balance) > 0) as $invoice) {
            $days = $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->diffInDays($today, false) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30_days' : ($days <= 60 ? '31_60_days' : ($days <= 90 ? '61_90_days' : 'over_90_days')));
            $aging->put($bucket, $aging->get($bucket) + $this->money->minor($invoice->balance));
        }
        $methodNames = DB::table('payment_methods')->where('school_id', $user->school_id)->pluck('method_name', 'id');

        return ['currency' => DB::table('finance_settings')->where('school_id', $user->school_id)->where('active', true)->value('currency') ?? 'KES', 'total_invoiced' => $this->money->decimal($invoiced), 'total_collected' => $this->money->decimal($collected), 'outstanding' => $this->money->decimal($invoiced - $collected), 'collection_rate' => $invoiced > 0 ? round($collected * 100 / $invoiced, 2) : null, 'unallocated_credits' => $this->money->decimal($payments->sum(fn ($row) => $this->money->minor($row->amount) - $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00'))), 'invoices_by_status' => $invoices->countBy('status'), 'payments_by_method' => $payments->groupBy('payment_method_id')->mapWithKeys(fn ($rows, $methodId) => [($methodNames[$methodId] ?? 'Unknown') => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00')))]), 'collections_by_date' => $payments->groupBy('payment_date')->map(fn ($rows) => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00')))), 'by_grade' => $invoices->groupBy('grade_id')->map($summarizeInvoices), 'by_stream' => $invoices->filter(fn ($invoice) => $invoice->stream_id)->groupBy('stream_id')->map($summarizeInvoices), 'overdue' => ['count' => $overdue->count(), 'amount' => $this->money->decimal($overdue->sum(fn ($invoice) => $this->money->minor($invoice->balance)))], 'aging' => $aging->map(fn ($minor) => $this->money->decimal($minor)), 'account_statuses' => DB::table('learner_fee_accounts')->where('school_id', $user->school_id)->select('account_status', DB::raw('COUNT(*) AS aggregate'))->groupBy('account_status')->pluck('aggregate', 'account_status'), 'reversed_payments' => DB::table('payments')->where('school_id', $user->school_id)->where('reversed', true)->count(), 'ledger_mismatch_count' => $ledgerMismatches, 'invoice_item_mismatch_count' => $invoiceItemMismatches, 'allocation_mismatch_count' => $allocationMismatches];
    }
}
