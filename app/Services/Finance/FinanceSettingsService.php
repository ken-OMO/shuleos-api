<?php

namespace App\Services\Finance;

use App\Models\FinanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceSettingsService
{
    public function __construct(private FinanceAuditService $audit) {}

    public function get(User $user): FinanceSetting
    {
        return FinanceSetting::firstOrCreate(['school_id' => $user->school_id], ['id' => (string) Str::uuid(), 'currency' => 'KES', 'allow_partial_payments' => true, 'allow_overpayments' => false, 'auto_generate_invoices' => false, 'active' => true]);
    }

    public function update(User $user, array $data): FinanceSetting
    {
        $settings = $this->get($user);
        if (($data['currency'] ?? $settings->currency) !== $settings->currency && DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->exists()) {
            throw ValidationException::withMessages(['currency' => 'Currency cannot change after financial postings exist.']);
        }
        $old = $settings->only(['currency', 'allow_partial_payments', 'allow_overpayments', 'auto_generate_invoices']);
        $settings->update($data);
        $this->audit->record($user, 'settings_updated', 'finance_settings', $settings->id, $old, $settings->only(array_keys($old)));

        return $settings;
    }
}
