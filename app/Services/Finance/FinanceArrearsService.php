<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceArrearsService
{
    public function __construct(private FinanceMoney $money, private FinanceLedgerService $ledger, private FinanceAuditService $audit) {}

    public function calculate(User $user, string $yearId, string $termId, ?string $learnerId = null): int
    {
        abort_unless(DB::table('terms')->where('id', $termId)->where('school_id', $user->school_id)->where('academic_year_id', $yearId)->exists(), 422, 'Invalid year and term.');

        return DB::transaction(function () use ($user, $yearId, $termId, $learnerId) {
            $groups = DB::table('fee_invoices')->where('school_id', $user->school_id)->where('academic_year_id', $yearId)->where('term_id', $termId)->whereIn('status', ['posted', 'partially_paid'])->when($learnerId, fn ($query) => $query->where('learner_id', $learnerId))->select('learner_id', 'learner_fee_account_id')->selectRaw('SUM(balance) AS amount')->groupBy('learner_id', 'learner_fee_account_id')->get();
            $count = 0;
            foreach ($groups as $group) {
                if ($this->money->minor($group->amount) <= 0) {
                    continue;
                }
                $existing = DB::table('fee_arrears')->where('school_id', $user->school_id)->where('learner_id', $group->learner_id)->where('academic_year_id', $yearId)->where('term_id', $termId)->first();
                DB::table('fee_arrears')->updateOrInsert(['school_id' => $user->school_id, 'learner_id' => $group->learner_id, 'academic_year_id' => $yearId, 'term_id' => $termId], ['id' => $existing?->id ?? (string) Str::uuid(), 'learner_fee_account_id' => $group->learner_fee_account_id, 'amount' => $group->amount, 'status' => 'outstanding', 'calculated_by' => $user->id, 'calculated_at' => now(), 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now()]);
                app(FinanceNotificationService::class)->forLearner($user->school_id, $group->learner_id, 'finance_arrears_created', 'finance:arrears:'.$yearId.':'.$termId.':'.$group->learner_id, 'Fee arrears updated', 'Your term fee arrears have been calculated. View your fee account for details.');
                $count++;
            }
            $this->audit->record($user, 'arrears_calculated', 'fee_arrears', $termId, [], ['count' => $count]);

            return $count;
        });
    }

    public function carryForward(User $user, string $id, string $targetYearId, string $targetTermId, string|int|null $requested = null): object
    {
        return DB::transaction(function () use ($user, $id, $targetYearId, $targetTermId, $requested) {
            $arrear = DB::table('fee_arrears')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'outstanding')->lockForUpdate()->first();
            abort_unless($arrear, 404);
            abort_unless(DB::table('terms')->where('id', $targetTermId)->where('school_id', $user->school_id)->where('academic_year_id', $targetYearId)->exists(), 422, 'Invalid target year and term.');
            abort_if($arrear->carried_forward_to_term_id, 409, 'Arrears have already been carried forward.');
            $remaining = $this->money->minor($arrear->amount) - $this->money->minor($arrear->carried_forward_amount);
            $amount = $requested === null ? $remaining : $this->money->minor($this->money->positive($requested));
            if ($amount > $remaining) {
                throw ValidationException::withMessages(['amount' => 'Carry-forward exceeds outstanding source arrears.']);
            }
            $account = LearnerFeeAccount::whereKey($arrear->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
            $creditId = $this->ledger->post($user, $account, ['academic_year_id' => $arrear->academic_year_id, 'term_id' => $arrear->term_id, 'transaction_type' => 'arrears_transfer_out', 'reference_type' => 'fee_arrear', 'reference_id' => $arrear->id, 'posting_action' => 'transfer_out', 'credit' => $this->money->decimal($amount), 'description' => 'Arrears transferred to a later term']);
            $debitId = $this->ledger->post($user, $account, ['academic_year_id' => $targetYearId, 'term_id' => $targetTermId, 'transaction_type' => 'arrears_carry_forward', 'reference_type' => 'fee_arrear', 'reference_id' => $arrear->id, 'posting_action' => 'carry_forward', 'debit' => $this->money->decimal($amount), 'description' => 'Arrears carried forward from prior term']);
            DB::table('fee_arrears')->where('id', $id)->update(['carried_forward_to_term_id' => $targetTermId, 'carried_forward_amount' => $this->money->decimal($amount), 'source_resolution_ledger_id' => $creditId, 'carry_forward_ledger_id' => $debitId, 'status' => 'carried_forward', 'updated_at' => now()]);
            $this->audit->record($user, 'arrears_carried_forward', 'fee_arrears', $id, [], ['amount' => $this->money->decimal($amount)]);

            return $this->find($user, $id);
        });
    }

    public function resolve(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $arrear = DB::table('fee_arrears')->where('id', $id)->where('school_id', $user->school_id)->lockForUpdate()->first();
            abort_unless($arrear, 404);
            $outstanding = DB::table('fee_invoices')->where('school_id', $user->school_id)->where('learner_id', $arrear->learner_id)->where('academic_year_id', $arrear->academic_year_id)->where('term_id', $arrear->term_id)->whereIn('status', ['posted', 'partially_paid'])->get()->sum(fn ($invoice) => $this->money->minor($invoice->balance));
            if ($outstanding > 0 && $arrear->status !== 'carried_forward') {
                throw ValidationException::withMessages(['arrear' => 'Source arrears remain outstanding.']);
            }
            DB::table('fee_arrears')->where('id', $id)->update(['status' => 'resolved', 'resolved_by' => $user->id, 'resolved_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'arrears_resolved', 'fee_arrears', $id);

            return $this->find($user, $id);
        });
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('fee_arrears')->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
