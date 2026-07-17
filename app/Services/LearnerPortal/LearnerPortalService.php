<?php

namespace App\Services\LearnerPortal;

use App\Models\LearnerDashboardPreference;
use App\Models\ReportCard;
use App\Models\SchoolSettings;
use App\Models\User;
use App\Services\Communication\CommunicationNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LearnerPortalService
{
    public function __construct(private readonly LearnerPortalAccessService $a, private readonly CommunicationNotificationService $communications) {}

    public function profile(User $u)
    {
        return $this->a->learner($u)->load('school', 'grade', 'stream');
    }

    private function settings(User $u)
    {
        return SchoolSettings::where('school_id', $u->school_id)->first();
    }

    public function preferences(User $u)
    {
        $l = $this->a->learner($u);
        $p = LearnerDashboardPreference::firstOrCreate(['school_id' => $u->school_id, 'learner_id' => $l->id], ['id' => (string) Str::uuid(), 'show_fees' => false]);
        $s = $this->settings($u);
        if ($s) {
            if (! $s->learner_portal_show_fees) {
                $p->show_fees = false;
            }if (! $s->learner_portal_show_attendance) {
                $p->show_attendance = false;
            }if (! $s->learner_portal_show_results) {
                $p->show_results = false;
            }if (! $s->learner_portal_show_report_cards) {
                $p->show_report_cards = false;
            }
        }

        return $p;
    }

    public function updatePreferences(User $u, array $d)
    {
        $s = $this->settings($u);
        $blocked = ['show_fees' => 'learner_portal_show_fees', 'show_attendance' => 'learner_portal_show_attendance', 'show_results' => 'learner_portal_show_results', 'show_report_cards' => 'learner_portal_show_report_cards'];
        foreach ($blocked as $f => $policy) {
            if (($d[$f] ?? false) && $s && ! $s->$policy) {
                throw new AuthorizationException('Widget is disabled by school policy.');
            }
        }$p = $this->preferences($u);
        $p->update($d);

        return $p;
    }

    public function timetable(User $u, ?int $day = null)
    {
        $l = $this->a->learner($u);

        return DB::table('timetable_entries as e')->join('timetables as t', 't.id', '=', 'e.timetable_id')->where('t.school_id', $u->school_id)->where('t.status', 'published')->where('t.active', true)->where('e.is_deleted', false)->where('e.grade_id', $l->grade_id)->where('e.stream_id', $l->stream_id)->when($day, fn ($q) => $q->where('e.day_of_week', $day))->orderBy('e.day_of_week')->orderBy('e.period_id')->get();
    }

    public function attendance(User $u)
    {
        $l = $this->a->learner($u);
        if (($s = $this->settings($u)) && ! $s->learner_portal_show_attendance) {
            throw new AuthorizationException('Attendance is disabled.');
        }$rows = DB::table('learner_attendance as a')->join('attendance_statuses as st', 'st.id', '=', 'a.attendance_status_id')->where('a.school_id', $u->school_id)->where('a.learner_id', $l->id)->select('a.attendance_date', 'st.status_code')->latest('a.attendance_date')->get();
        if ($rows->isEmpty()) {
            return ['available' => false, 'present' => null, 'absent' => null, 'late' => null, 'total_sessions' => null, 'percentage' => null, 'history' => []];
        }$codes = $rows->pluck('status_code')->map(fn ($x) => strtoupper($x));
        $p = $codes->filter(fn ($x) => in_array($x, ['P', 'PRESENT']))->count();
        $a = $codes->filter(fn ($x) => in_array($x, ['A', 'ABSENT']))->count();
        $late = $codes->filter(fn ($x) => in_array($x, ['L', 'LATE']))->count();

        return ['available' => true, 'present' => $p, 'absent' => $a, 'late' => $late, 'total_sessions' => $rows->count(), 'percentage' => round((($p + $late) / $rows->count()) * 100, 2), 'history' => $rows->take(30)];
    }

    public function results(User $u, array $f = [])
    {
        $l = $this->a->learner($u);
        if (($s = $this->settings($u)) && ! $s->learner_portal_show_results) {
            throw new AuthorizationException('Results are disabled.');
        }$q = DB::table('learning_area_results as r')->join('grading_scales as gs', 'gs.id', '=', 'r.grading_scale_id')->where('r.school_id', $u->school_id)->where('r.learner_id', $l->id)->where('r.processing_status', 'processed')->where('r.is_deleted', false)->when($f['exam_id'] ?? null, fn ($x, $v) => $x->where('r.exam_id', $v))->select('r.id', 'r.exam_id', 'r.learning_area_id', 'r.marks_obtained', 'r.maximum_marks', 'r.percentage', 'gs.grade_code', 'gs.grade_description', 'gs.points');

        return $q->get();
    }

    public function reportCards(User $u)
    {
        $l = $this->a->learner($u);
        if (($settings = $this->settings($u)) && ! $settings->learner_portal_show_report_cards) {
            throw new AuthorizationException('Report cards are disabled.');
        }

        return ReportCard::current()->where('school_id', $u->school_id)->where('learner_id', $l->id)->where('status', 'published')->with('exam', 'term', 'overallGradingScale')->get();
    }

    public function reportCard(User $u, string $id)
    {
        return $this->a->requireReportCard($u, $id)->load('exam', 'term', 'overallGradingScale', 'learningAreas.learningArea', 'learningAreas.gradingScale');
    }

    public function fees(User $u)
    {
        $l = $this->a->learner($u);
        if (($s = $this->settings($u)) && ! $s->learner_portal_show_fees) {
            throw new AuthorizationException('Fees are disabled.');
        }$i = DB::table('fee_invoices')->where('school_id', $u->school_id)->where('learner_id', $l->id)->whereNull('cancelled_at')->whereRaw("LOWER(COALESCE(status,'')) <> 'cancelled'");
        $p = DB::table('payments')->where('school_id', $u->school_id)->where('learner_id', $l->id)->where('reversed', false);

        return ['invoiced' => (float) $i->sum('total_amount'), 'paid' => (float) $i->sum('amount_paid'), 'balance' => (float) $i->sum('balance'), 'invoices' => $i->latest('invoice_date')->get(), 'recent_payments' => $p->select('id', 'amount', 'payment_date', 'receipt_number')->latest('payment_date')->limit(10)->get()];
    }

    public function exams(User $u)
    {
        $l = $this->a->learner($u);

        return DB::table('exams')->where('school_id', $u->school_id)->where('is_deleted', false)->whereIn('status', ['published', 'scheduled'])->where('end_date', '>=', today())->get();
    }

    public function announcements(User $u)
    {
        if (Schema::hasTable('communications') && Schema::hasTable('communication_recipient_snapshots')) {
            return $this->communications->portalAnnouncements($u);
        }

        return DB::table('broadcasts')->where('school_id', $u->school_id)->whereRaw('LOWER(status)=?', ['sent'])->whereNotNull('sent_at')->where(fn ($q) => $q->whereNull('target_group')->orWhereRaw('LOWER(target_group) IN (?,?)', ['learner', 'learners']))->latest('sent_at')->get();
    }

    public function notifications(User $u)
    {
        return DB::table('notifications')->where('school_id', $u->school_id)->where('user_id', $u->id)->latest('created_at')->paginate(20);
    }

    public function dashboard(User $u)
    {
        $p = $this->preferences($u);
        $d = ['profile' => $this->profile($u), 'preferences' => $p];
        $map = ['show_timetable' => fn () => $this->timetable($u, (int) now()->dayOfWeekIso), 'show_attendance' => fn () => $this->attendance($u), 'show_results' => fn () => $this->results($u), 'show_report_cards' => fn () => $this->reportCards($u), 'show_fees' => fn () => $this->fees($u), 'show_announcements' => fn () => $this->announcements($u), 'show_notifications' => fn () => $this->notifications($u), 'show_upcoming_exams' => fn () => $this->exams($u), 'show_learning_resources' => fn () => ['available' => false, 'message' => 'Learning resources are not yet available.']];
        foreach ($map as $k => $fn) {
            if ($p->$k) {
                $d[substr($k, 5)] = $fn();
            }
        }

        return $d;
    }
}
