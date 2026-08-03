<?php

namespace App\Services\Finance;

use App\Models\LearnerFeeAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceClearanceService
{
    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit) {}

    public function status(User $user, string $learnerId): object
    {
        $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $learnerId)->firstOrFail();
        $existing = DB::table('finance_clearances')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->first();
        $threshold = DB::table('finance_settings')->where('school_id', $user->school_id)->value('clearance_threshold') ?? '0.00';
        $balance = $account->current_balance;
        $overrideActive = $existing?->is_override && ! $existing?->revoked_at && (! $existing?->expires_at || $existing->expires_at >= now());
        $planActive = DB::table('payment_plans')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('status', 'active')->exists();
        $status = $overrideActive ? $existing->status : ($this->money->minor($balance) <= $this->money->minor($threshold) ? 'cleared' : ($planActive ? 'conditionally_cleared' : 'not_cleared'));
        $id = $existing?->id ?? (string) Str::uuid();
        DB::table('finance_clearances')->updateOrInsert(['school_id' => $user->school_id, 'learner_id' => $learnerId], ['id' => $id, 'learner_fee_account_id' => $account->id, 'status' => $status, 'balance_at_decision' => $balance, 'threshold' => $threshold, 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now()]);

        return DB::table('finance_clearances')->where('id', $id)->first();
    }

    public function override(User $user, string $learnerId, array $data): object
    {
        if (CarbonImmutable::parse($data['expires_at'])->isPast()) {
            throw ValidationException::withMessages(['expires_at' => 'Clearance override must expire in the future.']);
        }
        $row = $this->status($user, $learnerId);
        DB::table('finance_clearances')->where('id', $row->id)->update(['status' => $data['status'], 'is_override' => true, 'override_reason' => $data['reason'], 'approved_by' => $user->id, 'expires_at' => $data['expires_at'], 'revoked_by' => null, 'revoked_at' => null, 'revocation_reason' => null, 'updated_at' => now()]);
        if ($data['status'] === 'cleared') {
            DB::table('finance_clearance_certificates')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $learnerId, 'clearance_id' => $row->id, 'certificate_number' => 'CLR-'.now()->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10)), 'issued_at' => now(), 'expires_at' => $data['expires_at'], 'issued_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit->record($user, 'clearance_overridden', 'finance_clearances', $row->id);
        app(FinanceNotificationService::class)->forLearner($user->school_id, $learnerId, 'finance_clearance_changed', 'finance:clearance:'.$row->id.':override:'.md5($data['expires_at']), 'Fee clearance updated', 'Your fee-clearance status has been updated.');

        return DB::table('finance_clearances')->where('id', $row->id)->first();
    }

    public function revoke(User $user, string $learnerId, string $reason): object
    {
        $row = DB::table('finance_clearances')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('is_override', true)->lockForUpdate()->first();
        abort_unless($row, 404);
        DB::table('finance_clearances')->where('id', $row->id)->update(['status' => 'revoked', 'revoked_by' => $user->id, 'revoked_at' => now(), 'revocation_reason' => $reason, 'updated_at' => now()]);
        DB::table('finance_clearance_certificates')->where('clearance_id', $row->id)->whereNull('revoked_at')->update(['revoked_by' => $user->id, 'revoked_at' => now(), 'revocation_reason' => $reason, 'updated_at' => now()]);
        $this->audit->record($user, 'clearance_revoked', 'finance_clearances', $row->id);
        app(FinanceNotificationService::class)->forLearner($user->school_id, $learnerId, 'finance_clearance_revoked', 'finance:clearance:'.$row->id.':revoked', 'Fee clearance revoked', 'A temporary fee-clearance decision has been revoked.');

        return DB::table('finance_clearances')->where('id', $row->id)->first();
    }
}
