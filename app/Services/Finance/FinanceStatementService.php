<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinanceStatementService
{
    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit) {}

    public function statement(User $user, string $accountId, array $filters = [], bool $audit = true): array
    {
        $account = LearnerFeeAccount::whereKey($accountId)->where('school_id', $user->school_id)->firstOrFail();
        $query = DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('learner_fee_account_id', $account->id)
            ->when($filters['academic_year_id'] ?? null, fn ($q, $value) => $q->where('academic_year_id', $value))
            ->when($filters['term_id'] ?? null, fn ($q, $value) => $q->where('term_id', $value))
            ->when($filters['date_from'] ?? null, fn ($q, $value) => $q->whereDate('transaction_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->whereDate('transaction_date', '<=', $value))
            ->when($filters['transaction_type'] ?? null, fn ($q, $value) => $q->where('transaction_type', $value));
        $first = (clone $query)->orderBy('transaction_date')->orderBy('created_at')->orderBy('id')->first();
        $openingMinor = 0;
        if ($first) {
            $openingMinor = $this->money->minor($first->running_balance) - $this->money->minor($first->debit_amount) + $this->money->minor($first->credit_amount);
        }
        $entries = $query->select('transaction_date', 'transaction_type', 'reference_type', 'debit_amount', 'credit_amount', 'running_balance', 'description', 'posting_action', 'created_at')->orderBy('transaction_date')->orderBy('created_at')->orderBy('id')->get();
        $closingMinor = $openingMinor + $entries->sum(fn ($row) => $this->money->minor($row->debit_amount) - $this->money->minor($row->credit_amount));
        if ($audit) {
            $this->audit->record($user, 'statement_viewed', 'learner_fee_accounts', $account->id);
        }

        return ['account_number' => $account->account_number, 'currency' => DB::table('finance_settings')->where('school_id', $user->school_id)->value('currency') ?? 'KES', 'opening_balance' => $this->money->decimal($openingMinor), 'entries' => $entries, 'closing_balance' => $this->money->decimal($closingMinor)];
    }
}
