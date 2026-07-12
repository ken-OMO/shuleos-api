<?php

namespace App\Services\ParentPortal;

use App\Models\ReportCard;
use App\Models\ReportCardAccessOverride;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParentReportCardAccessService
{
    private const POLICIES = ['open', 'view_only_when_balance', 'download_only_when_cleared', 'fully_restricted_when_balance', 'admin_approval', 'balance_threshold'];

    public function __construct(private readonly ParentPortalAccessService $access) {}

    public function decision(User $user, string $learnerId, string $cardId): array
    {
        $this->access->requireLinkedLearner($user, $learnerId);
        $card = ReportCard::current()->whereKey($cardId)->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('status', 'published')->first();
        if (! $card) {
            throw new AuthorizationException('Published report card is unavailable.');
        }$balance = (float) DB::table('fee_invoices')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('academic_year_id', $card->academic_year_id)->where('term_id', $card->term_id)->whereNull('cancelled_at')->whereRaw("LOWER(COALESCE(status,'')) <> 'cancelled'")->sum('balance');
        $settings = SchoolSettings::where('school_id', $user->school_id)->first();
        $policy = $settings?->report_card_fee_policy ?? 'open';
        if (! in_array($policy, self::POLICIES, true)) {
            throw ValidationException::withMessages(['policy' => 'Unsupported report-card access policy.']);
        }$override = ReportCardAccessOverride::current()->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('access_allowed', true)->where(fn ($q) => $q->where('report_card_id', $card->id)->orWhere(fn ($x) => $x->whereNull('report_card_id')->where(fn ($y) => $y->where('exam_id', $card->exam_id)->orWhereNull('exam_id'))))->latest()->first();
        $view = $download = true;
        if ($policy === 'view_only_when_balance' && $balance > 0) {
            $view = $download = false;
        }if ($policy === 'download_only_when_cleared' && $balance > 0) {
            $download = false;
        }if ($policy === 'fully_restricted_when_balance' && $balance > 0) {
            $view = $download = false;
        }if ($policy === 'admin_approval') {
            $view = $download = false;
        }if ($policy === 'balance_threshold' && $balance > (float) ($settings?->report_card_balance_threshold ?? 0)) {
            $view = $download = false;
        }if ($override && ($settings?->report_card_allow_admin_override ?? true)) {
            if (in_array($override->access_scope, ['view', 'both'])) {
                $view = true;
            }if (in_array($override->access_scope, ['download', 'both'])) {
                $download = true;
            }
        }

        return ['report_card' => $card, 'can_view' => $view, 'can_download' => $download, 'outstanding_balance' => round($balance, 2), 'policy' => $policy, 'restriction_message' => $settings?->report_card_restriction_message, 'override_applied' => (bool) $override];
    }

    public function requireView(User $user, string $l, string $c): array
    {
        $d = $this->decision($user, $l, $c);
        if (! $d['can_view']) {
            throw new AuthorizationException($d['restriction_message'] ?: 'Report card access is restricted.');
        }

        return $d;
    }

    public function requireDownload(User $user, string $l, string $c): array
    {
        $d = $this->decision($user, $l, $c);
        if (! $d['can_download']) {
            throw new AuthorizationException($d['restriction_message'] ?: 'Report card download is restricted.');
        }

        return $d;
    }
}
