<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceAdjustmentService
{
    private const DIRECTIONS = ['debit_adjustment' => 'debit', 'credit_adjustment' => 'credit', 'write_off' => 'credit', 'correction' => 'credit', 'opening_balance' => 'debit', 'transfer_credit' => 'credit', 'penalty_reversal' => 'credit', 'other' => 'debit'];

    public function __construct(private FinanceMoney $money, private FinanceLedgerService $ledger, private FinanceAuditService $audit) {}

    public function create(User $user, array $data): object
    {
        $type = $data['adjustment_type'];
        if (! isset(self::DIRECTIONS[$type])) {
            throw ValidationException::withMessages(['adjustment_type' => 'Unsupported adjustment type.']);
        }
        $account = LearnerFeeAccount::whereKey($data['learner_fee_account_id'])->where('school_id', $user->school_id)->firstOrFail();
        if ($type === 'opening_balance' && DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('learner_fee_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['adjustment_type' => 'Opening balance is only allowed before an account has ledger history.']);
        }
        $id = (string) Str::uuid();
        DB::table('finance_adjustments')->insert(['id' => $id, 'school_id' => $user->school_id, 'learner_id' => $account->learner_id, 'learner_fee_account_id' => $account->id, 'academic_year_id' => $data['academic_year_id'] ?? null, 'term_id' => $data['term_id'] ?? null, 'adjustment_type' => $type, 'direction' => self::DIRECTIONS[$type], 'amount' => $this->money->positive($data['amount']), 'reason' => $data['reason'], 'reference_type' => $data['reference_type'] ?? null, 'reference_id' => $data['reference_id'] ?? null, 'created_by' => $user->id, 'status' => 'draft', 'created_at' => now()]);
        $this->audit->record($user, 'adjustment_created', 'finance_adjustments', $id);

        return $this->find($user, $id);
    }

    public function transition(User $user, string $id, string $to, ?string $reason = null): object
    {
        return DB::transaction(function () use ($user, $id, $to, $reason) {
            $row = DB::table('finance_adjustments')->where('id', $id)->where('school_id', $user->school_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $allowed = ['draft' => ['submitted', 'cancelled'], 'submitted' => ['approved', 'rejected'], 'approved' => ['posted'], 'posted' => ['reversed']];
            if (! in_array($to, $allowed[$row->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid adjustment transition.']);
            }
            if (in_array($to, ['approved', 'rejected'], true) && $row->created_by === $user->id) {
                throw ValidationException::withMessages(['approval' => 'Adjustment maker cannot approve or reject their own adjustment.']);
            }
            if (in_array($to, ['rejected', 'cancelled', 'reversed'], true) && ! $reason) {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            $values = ['status' => $to, 'updated_at' => now()];
            if ($to === 'submitted') {
                $values += ['submitted_by' => $user->id, 'submitted_at' => now()];
            } elseif ($to === 'approved') {
                $values += ['approved_by' => $user->id, 'approved_at' => now()];
            } elseif ($to === 'rejected') {
                $values += ['rejected_by' => $user->id, 'rejected_at' => now(), 'decision_reason' => $reason];
            } elseif ($to === 'posted') {
                $account = LearnerFeeAccount::whereKey($row->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
                $ledgerId = $this->ledger->post($user, $account, ['academic_year_id' => $row->academic_year_id, 'term_id' => $row->term_id, 'transaction_type' => $row->adjustment_type, 'reference_type' => 'finance_adjustment', 'reference_id' => $row->id, $row->direction => $row->amount, 'description' => $row->reason]);
                $values += ['posted_by' => $user->id, 'posted_at' => now(), 'ledger_entry_id' => $ledgerId];
            } elseif ($to === 'reversed') {
                $account = LearnerFeeAccount::whereKey($row->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
                $direction = $row->direction === 'debit' ? 'credit' : 'debit';
                $ledgerId = $this->ledger->post($user, $account, ['academic_year_id' => $row->academic_year_id, 'term_id' => $row->term_id, 'transaction_type' => 'adjustment_reversal', 'reference_type' => 'finance_adjustment', 'reference_id' => $row->id, 'posting_action' => 'reversal', $direction => $row->amount, 'reverses_ledger_id' => $row->ledger_entry_id, 'description' => 'Reversal: '.$reason]);
                $values += ['reversal_ledger_entry_id' => $ledgerId, 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason];
            }
            DB::table('finance_adjustments')->where('id', $id)->update($values);
            $this->audit->record($user, 'adjustment_'.$to, 'finance_adjustments', $id);

            return $this->find($user, $id);
        });
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('finance_adjustments')->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
