<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceLearnerDiscountService
{
    public function __construct(private FinanceAuditService $audit) {}

    public function assign(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $data['learner_id'])->where('account_status', 'active')->lockForUpdate()->firstOrFail();
            $discount = DB::table('fee_discounts')->where('id', $data['discount_id'])->where('school_id', $user->school_id)->where('status', 'active')->where('active', true)->where('is_deleted', false)->first();
            if (! $discount || ($discount->effective_from && $discount->effective_from > now()->toDateString()) || ($discount->effective_to && $discount->effective_to < now()->toDateString())) {
                throw ValidationException::withMessages(['discount_id' => 'Discount is not currently assignable.']);
            }
            abort_unless(DB::table('academic_years')->where('id', $data['academic_year_id'])->where('school_id', $user->school_id)->exists(), 422, 'Invalid academic year.');
            if (! empty($data['term_id'])) {
                abort_unless(DB::table('terms')->where('id', $data['term_id'])->where('school_id', $user->school_id)->where('academic_year_id', $data['academic_year_id'])->exists(), 422, 'Invalid term.');
            }
            if (! empty($data['fee_category_id'])) {
                abort_unless(DB::table('fee_categories')->where('id', $data['fee_category_id'])->where('school_id', $user->school_id)->exists(), 422, 'Invalid category.');
            }
            if (! empty($data['starts_at']) && ! empty($data['ends_at']) && $data['ends_at'] < $data['starts_at']) {
                throw ValidationException::withMessages(['ends_at' => 'Assignment end must not precede its start.']);
            }
            $overlap = DB::table('learner_discounts')->where('school_id', $user->school_id)->where('learner_id', $data['learner_id'])->where('discount_id', $discount->id)->where('academic_year_id', $data['academic_year_id'])->whereIn('status', ['pending', 'approved', 'active'])->when($data['term_id'] ?? null, fn ($query, $term) => $query->where('term_id', $term), fn ($query) => $query->whereNull('term_id'))->when($data['fee_category_id'] ?? null, fn ($query, $category) => $query->where('fee_category_id', $category), fn ($query) => $query->whereNull('fee_category_id'))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['discount_id' => 'An overlapping learner discount already exists.']);
            }
            $override = $data['assigned_value'] ?? null;
            if ($override !== null && empty($data['override_reason'])) {
                throw ValidationException::withMessages(['override_reason' => 'A reason is required for a value override.']);
            }
            $id = (string) Str::uuid();
            DB::table('learner_discounts')->insert(['id' => $id, 'school_id' => $user->school_id, 'learner_id' => $data['learner_id'], 'learner_fee_account_id' => $account->id, 'discount_id' => $discount->id, 'academic_year_id' => $data['academic_year_id'], 'term_id' => $data['term_id'] ?? null, 'fee_category_id' => $data['fee_category_id'] ?? null, 'status' => 'pending', 'assigned_value' => $override ?? $discount->discount_value, 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'override_reason' => $data['override_reason'] ?? null, 'private_notes' => $data['private_notes'] ?? null, 'assigned_by' => $user->id, 'active' => false, 'is_deleted' => false, 'created_at' => now()]);
            $this->audit->record($user, 'learner_discount_assigned', 'learner_discounts', $id);

            return $this->find($user, $id);
        });
    }

    public function approve(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $row = DB::table('learner_discounts')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('learner_discounts')->where('id', $id)->update(['status' => 'active', 'active' => true, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'learner_discount_approved', 'learner_discounts', $id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $row->learner_id, 'finance_discount_approved', 'finance:learner-discount:'.$id.':approved', 'Fee benefit approved', 'A fee discount or benefit has been approved for your account.');

            return $this->find($user, $id);
        });
    }

    public function cancel(User $user, string $id, string $reason): object
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $row = DB::table('learner_discounts')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['pending', 'approved', 'active'])->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if(DB::table('fee_discount_applications')->where('learner_discount_id', $id)->where('status', 'active')->exists(), 409, 'Applied discount must be reversed before cancellation.');
            DB::table('learner_discounts')->where('id', $id)->update(['status' => 'cancelled', 'active' => false, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record($user, 'learner_discount_cancelled', 'learner_discounts', $id);

            return $this->find($user, $id);
        });
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('learner_discounts')->where('id', $id)->where('school_id', $user->school_id)->where('is_deleted', false)->first();
        abort_unless($row, 404);

        return $row;
    }
}
