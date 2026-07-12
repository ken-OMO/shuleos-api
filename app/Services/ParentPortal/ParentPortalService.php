<?php

namespace App\Services\ParentPortal;

use App\Models\ReportCard;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ParentPortalService
{
    public function __construct(private readonly ParentPortalAccessService $access, private readonly ParentReportCardAccessService $cards) {}

    public function profile(User $u)
    {
        return $this->access->parent($u)->load('user');
    }

    public function learners(User $u)
    {
        return $this->access->links($u)->map(fn ($x) => ['learner' => $x->learner, 'relationship' => $x->relationship ?? $x->guardian?->relationship, 'is_primary_contact' => $x->is_primary_contact]);
    }

    public function reportCards(User $u, string $l)
    {
        $this->access->requireLinkedLearner($u, $l);

        return ReportCard::current()->where('school_id', $u->school_id)->where('learner_id', $l)->where('status', 'published')->latest('published_at')->get()->map(fn ($c) => ['report_card' => $c, 'access' => collect($this->cards->decision($u, $l, $c->id))->except('report_card')]);
    }

    public function reportCard(User $u, string $l, string $c)
    {
        $d = $this->cards->requireView($u, $l, $c);

        return $d['report_card']->load('exam', 'term', 'overallGradingScale', 'pathwayRecommendation', 'learningAreas.learningArea', 'learningAreas.gradingScale');
    }

    public function fees(User $u, string $l)
    {
        $this->access->requireLinkedLearner($u, $l);
        $settings = SchoolSettings::where('school_id', $u->school_id)->first();
        if ($settings && ! $settings->parent_portal_show_fees) {
            return ['enabled' => false];
        }$invoices = DB::table('fee_invoices')->where('school_id', $u->school_id)->where('learner_id', $l)->whereNull('cancelled_at')->latest('invoice_date')->get();

        return ['enabled' => true, 'invoiced' => (float) $invoices->sum('total_amount'), 'paid' => (float) $invoices->sum('amount_paid'), 'balance' => (float) $invoices->sum('balance'), 'invoices' => $invoices];
    }

    public function attendance(User $u, string $l)
    {
        $this->access->requireLinkedLearner($u, $l);
        $settings = SchoolSettings::where('school_id', $u->school_id)->first();
        if ($settings && ! $settings->parent_portal_show_attendance) {
            return ['enabled' => false];
        }$rows = DB::table('learner_attendance as a')->join('attendance_statuses as s', 's.id', '=', 'a.attendance_status_id')->where('a.school_id', $u->school_id)->where('a.learner_id', $l)->pluck('s.status_code')->map(fn ($x) => strtoupper($x));
        if ($rows->isEmpty()) {
            return ['enabled' => true, 'available' => false];
        }$p = $rows->filter(fn ($x) => in_array($x, ['P', 'PRESENT']))->count();
        $a = $rows->filter(fn ($x) => in_array($x, ['A', 'ABSENT']))->count();
        $late = $rows->filter(fn ($x) => in_array($x, ['L', 'LATE']))->count();

        return ['enabled' => true, 'available' => true, 'present' => $p, 'absent' => $a, 'late' => $late, 'total_sessions' => $rows->count(), 'percentage' => round((($p + $late) / $rows->count()) * 100, 2)];
    }

    public function announcements(User $u)
    {
        $s = SchoolSettings::where('school_id', $u->school_id)->first();
        if ($s && ! $s->parent_portal_show_announcements) {
            return [];
        }

        return DB::table('broadcasts')->where('school_id', $u->school_id)->whereRaw('LOWER(status) = ?', ['sent'])->whereNotNull('sent_at')->where(fn ($q) => $q->whereNull('target_group')->orWhereRaw('LOWER(target_group) IN (?,?)', ['parent', 'parents']))->latest('sent_at')->limit(20)->get();
    }

    public function notifications(User $u)
    {
        return DB::table('notifications')->where('school_id', $u->school_id)->where('user_id', $u->id)->latest('created_at')->paginate(20);
    }

    public function dashboard(User $u, string $l)
    {
        $learner = $this->access->requireLinkedLearner($u, $l);
        $cards = $this->reportCards($u, $l);

        return ['learner' => $learner->load('grade', 'stream'), 'latest_report_card' => $cards->first(), 'fees' => $this->fees($u, $l), 'attendance' => $this->attendance($u, $l), 'unread_notifications' => DB::table('notifications')->where('school_id', $u->school_id)->where('user_id', $u->id)->where('is_read', false)->count(), 'announcements' => $this->announcements($u)->take(5)];
    }
}
