<?php

namespace App\Services\LearnerPortal;

use App\Models\ReportCard;
use App\Models\ReportCardAccessOverride;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearnerReportCardAccessService
{
    private const POLICIES = ['open', 'view_only_when_balance', 'download_only_when_cleared', 'fully_restricted_when_balance', 'admin_approval', 'balance_threshold'];

    public function __construct(private LearnerPortalAccessService $access, private LearnerPortalAuditService $audit) {}

    public function decision(User $user, string $cardId): array
    {
        $learner = $this->access->learner($user);
        $card = ReportCard::withoutGlobalScopes()->whereKey($cardId)->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('status', 'published')->where('is_deleted', false)->first();
        if (! $card) {
            throw new AuthorizationException('Published report card is unavailable.');
        }
        $balance = (float) DB::table('fee_invoices')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('academic_year_id', $card->academic_year_id)->where('term_id', $card->term_id)->whereNull('cancelled_at')->whereRaw("LOWER(COALESCE(status,'')) <> 'cancelled'")->sum('balance');
        $settings = SchoolSettings::withoutGlobalScopes()->where('school_id', $user->school_id)->first();
        $policy = $settings?->report_card_fee_policy ?? 'open';
        if (! in_array($policy, self::POLICIES, true)) {
            throw ValidationException::withMessages(['policy' => 'Unsupported report-card access policy.']);
        }
        $override = ReportCardAccessOverride::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('access_allowed', true)->where(fn ($query) => $query->where('report_card_id', $card->id)->orWhere(fn ($nested) => $nested->whereNull('report_card_id')->where(fn ($scope) => $scope->where('exam_id', $card->exam_id)->orWhereNull('exam_id'))))->latest()->first();
        $view = $download = true;
        if (in_array($policy, ['view_only_when_balance', 'fully_restricted_when_balance'], true) && $balance > 0) {
            $view = $download = false;
        }
        if ($policy === 'download_only_when_cleared' && $balance > 0) {
            $download = false;
        }
        if ($policy === 'admin_approval') {
            $view = $download = false;
        }
        if ($policy === 'balance_threshold' && $balance > (float) ($settings?->report_card_balance_threshold ?? 0)) {
            $view = $download = false;
        }
        if ($override && ($settings?->report_card_allow_admin_override ?? true)) {
            $view = $view || in_array($override->access_scope, ['view', 'both'], true);
            $download = $download || in_array($override->access_scope, ['download', 'both'], true);
        }

        return compact('card', 'view', 'download', 'balance', 'policy');
    }

    public function requireView(User $user, string $cardId): ReportCard
    {
        $decision = $this->decision($user, $cardId);
        if (! $decision['view']) {
            throw new AuthorizationException('Report card access is restricted by school fee policy.');
        }

        return $decision['card'];
    }

    public function requireDownload(User $user, string $cardId): ReportCard
    {
        $decision = $this->decision($user, $cardId);
        if (! $decision['download']) {
            throw new AuthorizationException('Report card download is restricted by school fee policy.');
        }

        $this->audit->record($user, 'report_card_download_authorized', 'report_card', $cardId, ['policy' => $decision['policy']]);

        return $decision['card'];
    }
}
