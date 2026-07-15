<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceAccountService
{
    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit) {}

    public function provision(User $user, string $learnerId): LearnerFeeAccount
    {
        return DB::transaction(function () use ($user, $learnerId) {
            $learner = DB::table('learners')->where('id', $learnerId)->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)->lockForUpdate()->firstOrFail();
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $learner->id)->lockForUpdate()->first();
            if ($account) {
                return $account;
            }
            $account = LearnerFeeAccount::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $learner->id, 'account_number' => 'FEE-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 12)), 'current_balance' => '0.00', 'account_status' => 'active', 'active' => true]);
            $this->audit->record($user, 'account_provisioned', 'learner_fee_accounts', $account->id);

            return $account;
        });
    }

    public function provisionBulk(User $user, ?string $gradeId = null, ?string $streamId = null): Collection
    {
        if ($gradeId && ! DB::table('grades')->where('id', $gradeId)->where('school_id', $user->school_id)->exists()) {
            throw ValidationException::withMessages(['grade_id' => 'Grade must belong to the authenticated school.']);
        }
        if ($streamId && ! DB::table('streams')->where('id', $streamId)->where('school_id', $user->school_id)->when($gradeId, fn ($query) => $query->where('grade_id', $gradeId))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream must belong to the authenticated school and selected grade.']);
        }

        return DB::transaction(function () use ($user, $gradeId, $streamId) {
            return DB::table('learners')->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)
                ->when($gradeId, fn ($query) => $query->where('grade_id', $gradeId))
                ->when($streamId, fn ($query) => $query->where('stream_id', $streamId))
                ->orderBy('id')->pluck('id')->map(fn ($learnerId) => $this->provision($user, $learnerId));
        });
    }

    public function recalculate(User $user, string $id): LearnerFeeAccount
    {
        return DB::transaction(function () use ($user, $id) {
            $account = LearnerFeeAccount::whereKey($id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            $rows = DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('learner_fee_account_id', $account->id)->get();
            $minor = $rows->sum(fn ($row) => $this->money->minor($row->debit_amount) - $this->money->minor($row->credit_amount));
            $old = $account->current_balance;
            $account->update(['current_balance' => $this->money->decimal($minor), 'updated_at' => now()]);
            $this->audit->record($user, 'account_recalculated', 'learner_fee_accounts', $account->id, ['current_balance' => $old], ['current_balance' => $account->current_balance]);

            return $account;
        });
    }
}
