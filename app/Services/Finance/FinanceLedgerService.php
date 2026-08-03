<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceLedgerService
{
    public function __construct(private FinanceMoney $money) {}

    public function post(User $user, LearnerFeeAccount $account, array $data): string
    {
        return DB::transaction(function () use ($user, $account, $data) {
            $account = LearnerFeeAccount::whereKey($account->id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            $debit = $this->money->minor($data['debit'] ?? '0.00');
            $credit = $this->money->minor($data['credit'] ?? '0.00');
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(['ledger' => 'Exactly one of debit or credit must be positive.']);
            }
            $existing = DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('reference_type', $data['reference_type'])->where('reference_id', $data['reference_id'])->where('posting_action', $data['posting_action'] ?? 'original')->value('id');
            if ($existing) {
                return $existing;
            }
            $balance = $this->money->minor($account->current_balance) + $debit - $credit;
            $id = (string) Str::uuid();
            DB::table('learner_fee_ledger')->insert(['id' => $id, 'school_id' => $user->school_id, 'learner_id' => $account->learner_id, 'learner_fee_account_id' => $account->id, 'academic_year_id' => $data['academic_year_id'] ?? null, 'term_id' => $data['term_id'] ?? null, 'transaction_date' => $data['transaction_date'] ?? now()->toDateString(), 'transaction_type' => $data['transaction_type'], 'reference_type' => $data['reference_type'], 'reference_id' => $data['reference_id'], 'posting_action' => $data['posting_action'] ?? 'original', 'reverses_ledger_id' => $data['reverses_ledger_id'] ?? null, 'debit_amount' => $this->money->decimal($debit), 'credit_amount' => $this->money->decimal($credit), 'running_balance' => $this->money->decimal($balance), 'description' => $data['description'], 'created_by' => $user->id, 'created_at' => now()]);
            $account->update(['current_balance' => $this->money->decimal($balance), 'updated_at' => now()]);

            return $id;
        });
    }
}
