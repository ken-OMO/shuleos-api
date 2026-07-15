<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePaymentPlanService
{
    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit, private FinanceInstallmentService $installments) {}

    public function save(User $user, array $data, ?string $id = null): object
    {
        return DB::transaction(function () use ($user, $data, $id) {
            abort_unless(DB::table('academic_years')->where('id', $data['academic_year_id'])->where('school_id', $user->school_id)->exists(), 422, 'Invalid academic year.');
            abort_unless(DB::table('terms')->where('id', $data['term_id'])->where('school_id', $user->school_id)->where('academic_year_id', $data['academic_year_id'])->exists(), 422, 'Invalid term.');
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $data['learner_id'])->where('account_status', 'active')->firstOrFail();
            $invoices = DB::table('fee_invoices')->where('school_id', $user->school_id)->where('learner_id', $account->learner_id)->whereIn('id', array_unique($data['invoice_ids']))->whereIn('status', ['posted', 'partially_paid'])->get();
            if ($invoices->count() !== count(array_unique($data['invoice_ids']))) {
                throw ValidationException::withMessages(['invoice_ids' => 'All linked invoices must be posted and belong to the learner and school.']);
            }
            $totalMinor = $invoices->sum(fn ($invoice) => $this->money->minor($invoice->balance));
            if ($totalMinor <= 0) {
                throw ValidationException::withMessages(['invoice_ids' => 'Payment plan requires an outstanding invoice balance.']);
            }
            $installmentData = collect($data['installments'])->sortBy('installment_order')->values();
            if ($installmentData->pluck('installment_order')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['installments' => 'Installment order must be unique.']);
            }
            $scheduledMinor = $installmentData->sum(fn ($row) => $this->money->minor($this->money->positive($row['amount'])));
            if ($scheduledMinor !== $totalMinor) {
                throw ValidationException::withMessages(['installments' => 'Installments must sum exactly to the plan amount.']);
            }
            $dates = $installmentData->pluck('due_date')->all();
            if ($dates !== collect($dates)->sort()->values()->all() || count($dates) !== count(array_unique($dates))) {
                throw ValidationException::withMessages(['installments' => 'Installment dates must be unique and ordered.']);
            }
            if ($id) {
                $plan = DB::table('payment_plans')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'draft')->lockForUpdate()->first();
                abort_unless($plan, 404);
                DB::table('payment_plan_installments')->where('payment_plan_id', $id)->delete();
                DB::table('payment_plan_invoices')->where('payment_plan_id', $id)->delete();
            } else {
                $id = (string) Str::uuid();
            }
            $values = ['school_id' => $user->school_id, 'learner_id' => $account->learner_id, 'learner_fee_account_id' => $account->id, 'academic_year_id' => $data['academic_year_id'], 'term_id' => $data['term_id'], 'plan_name' => trim($data['plan_name']), 'description' => $data['description'] ?? null, 'number_of_installments' => $installmentData->count(), 'total_planned_amount' => $this->money->decimal($totalMinor), 'active' => false, 'updated_at' => now()];
            if (DB::table('payment_plans')->where('id', $id)->exists()) {
                DB::table('payment_plans')->where('id', $id)->update($values);
            } else {
                DB::table('payment_plans')->insert($values + ['id' => $id, 'status' => 'draft', 'created_by' => $user->id, 'is_deleted' => false, 'created_at' => now()]);
            }
            foreach ($invoices as $invoice) {
                DB::table('payment_plan_invoices')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'payment_plan_id' => $id, 'invoice_id' => $invoice->id, 'created_at' => now()]);
            }
            $allocated = 0;
            foreach ($installmentData as $index => $row) {
                $amount = $this->money->minor($row['amount']);
                $allocated += $amount;
                $basisPoints = $index === $installmentData->count() - 1 ? 10000 - DB::table('payment_plan_installments')->where('payment_plan_id', $id)->get()->sum(fn ($item) => $this->money->minor($item->percentage)) : intdiv($amount * 10000, $totalMinor);
                DB::table('payment_plan_installments')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'payment_plan_id' => $id, 'installment_name' => $row['name'] ?? 'Installment '.$row['installment_order'], 'percentage' => $this->money->decimal($basisPoints), 'due_days' => 0, 'installment_order' => $row['installment_order'], 'scheduled_amount' => $this->money->decimal($amount), 'paid_amount' => '0.00', 'outstanding_amount' => $this->money->decimal($amount), 'due_date' => $row['due_date'], 'status' => 'pending', 'created_at' => now()]);
            }
            $this->audit->record($user, 'payment_plan_saved', 'payment_plans', $id);

            return $this->find($user, $id);
        });
    }

    public function transition(User $user, string $id, string $to, ?string $reason = null): object
    {
        return DB::transaction(function () use ($user, $id, $to, $reason) {
            $plan = DB::table('payment_plans')->where('id', $id)->where('school_id', $user->school_id)->where('is_deleted', false)->lockForUpdate()->first();
            abort_unless($plan, 404);
            $allowed = ['draft' => ['approved', 'cancelled'], 'approved' => ['active', 'cancelled'], 'active' => ['cancelled', 'defaulted']];
            if (! in_array($to, $allowed[$plan->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid plan transition.']);
            }
            if ($to === 'cancelled' && ! $reason) {
                throw ValidationException::withMessages(['reason' => 'Cancellation reason is required.']);
            }
            $values = ['status' => $to, 'active' => $to === 'active', 'updated_at' => now()];
            if ($to === 'approved') {
                $values += ['approved_by' => $user->id, 'approved_at' => now()];
            } elseif ($to === 'active') {
                $values['activated_at'] = now();
            } elseif ($to === 'cancelled') {
                $values += ['cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason];
                DB::table('payment_plan_installments')->where('payment_plan_id', $id)->whereNotIn('status', ['paid'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            DB::table('payment_plans')->where('id', $id)->update($values);
            $this->audit->record($user, 'payment_plan_'.$to, 'payment_plans', $id);
            if ($to === 'active') {
                app(FinanceNotificationService::class)->forLearner($user->school_id, $plan->learner_id, 'finance_payment_plan_active', 'finance:payment-plan:'.$id.':active', 'Fee payment plan activated', 'Your approved fee payment plan is now active.');
            }

            return $this->find($user, $id);
        });
    }

    public function reschedule(User $user, string $id, array $dates, string $reason): object
    {
        return DB::transaction(function () use ($user, $id, $dates, $reason) {
            $plan = DB::table('payment_plans')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['approved', 'active'])->lockForUpdate()->first();
            abort_unless($plan, 404);
            $installments = DB::table('payment_plan_installments')->where('payment_plan_id', $id)->orderBy('installment_order')->get();
            if ($installments->count() !== count($dates) || $dates !== collect($dates)->sort()->values()->all()) {
                throw ValidationException::withMessages(['due_dates' => 'Provide one ordered due date for every installment.']);
            }
            foreach ($installments as $index => $installment) {
                DB::table('payment_plan_installments')->where('id', $installment->id)->update(['due_date' => $dates[$index], 'updated_at' => now()]);
            }
            $this->installments->refreshPlan($user, $id);
            $this->audit->record($user, 'payment_plan_rescheduled', 'payment_plans', $id, [], ['reason' => $reason]);

            return $this->find($user, $id);
        });
    }

    public function find(User $user, string $id): object
    {
        $plan = DB::table('payment_plans')->where('id', $id)->where('school_id', $user->school_id)->where('is_deleted', false)->first();
        abort_unless($plan, 404);
        $plan->installments = DB::table('payment_plan_installments')->where('payment_plan_id', $id)->orderBy('installment_order')->get();
        $plan->invoice_numbers = DB::table('payment_plan_invoices as link')->join('fee_invoices as invoice', 'invoice.id', '=', 'link.invoice_id')->where('link.payment_plan_id', $id)->pluck('invoice.invoice_number');

        return $plan;
    }
}
