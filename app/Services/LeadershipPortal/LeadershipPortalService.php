<?php

namespace App\Services\LeadershipPortal;

use App\Models\LeadershipDashboardPreference;
use App\Models\User;
use App\Services\Communication\CommunicationNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadershipPortalService
{
    private const PREF = ['show_attendance', 'show_teacher_attendance', 'show_curriculum_coverage', 'show_pending_approvals', 'show_lesson_plans', 'show_records_of_work', 'show_exams', 'show_report_cards', 'show_academic_performance', 'show_discipline', 'show_finance', 'show_announcements', 'show_notifications', 'show_teacher_workload', 'show_learner_enrolment'];

    public function __construct(private readonly LeadershipPortalAccessService $a, private readonly CommunicationNotificationService $communications) {}

    public function profile(User $u)
    {
        return ['user' => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name), 'email' => $u->email], 'role' => $this->a->scope($u)];
    }

    public function preferences(User $u)
    {
        $s = $this->a->scope($u);
        $p = LeadershipDashboardPreference::firstOrCreate(['school_id' => $u->school_id, 'user_id' => $u->id], ['id' => (string) Str::uuid()]);
        if (! $s['finance']) {
            $p->show_finance = false;
        }if (! $s['discipline']) {
            $p->show_discipline = false;
        }

        return $p;
    }

    public function updatePreferences(User $u, array $d)
    {
        $p = $this->preferences($u);
        $s = $this->a->scope($u);
        if (isset($d['show_finance']) && $d['show_finance'] && ! $s['finance']) {
            throw new AuthorizationException('Finance widget is not permitted.');
        }if (isset($d['show_discipline']) && $d['show_discipline'] && ! $s['discipline']) {
            throw new AuthorizationException('Discipline widget is not permitted.');
        }$p->update($d);

        return $p;
    }

    private function scope(User $u)
    {
        return $this->a->scope($u);
    }

    public function attendance(User $u)
    {
        $this->a->require($u, 'view_school_attendance_summary');
        $s = $this->scope($u);
        $rows = DB::table('learner_attendance as a')->join('attendance_statuses as st', 'st.id', '=', 'a.attendance_status_id')->where('a.school_id', $s['school_id'])->selectRaw('UPPER(st.status_code) code, COUNT(*) total')->groupBy('st.status_code')->pluck('total', 'code');
        if ($rows->isEmpty()) {
            return ['available' => false, 'present' => null, 'absent' => null, 'late' => null, 'total_sessions' => null, 'percentage' => null];
        }$p = (int) ($rows['P'] ?? $rows['PRESENT'] ?? 0);
        $a = (int) ($rows['A'] ?? $rows['ABSENT'] ?? 0);
        $l = (int) ($rows['L'] ?? $rows['LATE'] ?? 0);
        $t = $p + $a + $l;

        return ['available' => true, 'present' => $p, 'absent' => $a, 'late' => $l, 'total_sessions' => $t, 'percentage' => $t ? round((($p + $l) / $t) * 100, 2) : null];
    }

    public function curriculum(User $u)
    {
        $this->a->require($u, 'view_school_curriculum_summary');
        $s = $this->scope($u);
        $q = DB::table('curriculum_coverage')->where('school_id', $s['school_id'])->where('is_deleted', false);
        if ($s['role'] === 'HOD') {
            $q->whereIn('teacher_assignment_id', DB::table('teacher_assignments')->where('school_id', $s['school_id'])->whereIn('learning_area_id', $s['learning_area_ids'])->pluck('id'));
        }$total = (clone $q)->count();

        return ['total' => $total, 'completed' => (clone $q)->where('completed', true)->count(), 'percentage' => $total ? round(((clone $q)->where('completed', true)->count() / $total) * 100, 2) : null];
    }

    public function approvals(User $u)
    {
        $s = $this->scope($u);
        if (! $s['approvals']) {
            throw new AuthorizationException('Approval visibility denied.');
        }$q = DB::table('approvals')->where('school_id', $s['school_id']);
        if ($s['role'] === 'HOD') {
            $q->where('approver_id', $u->id);
        }

        return $q->latest('created_at')->paginate(20);
    }

    public function approval(User $u, string $id)
    {
        return $this->approvalRow($u, $id);
    }

    public function decide(User $u, string $id, string $status, ?string $comments)
    {
        $s = $this->scope($u);
        if (! $s['manage_approvals']) {
            throw new AuthorizationException('Approval management denied.');
        }$row = $this->approvalRow($u, $id);
        if (strtolower($row->approval_status) !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'Finalized approvals cannot be overwritten.']);
        }DB::table('approvals')->where('id', $id)->update(['approval_status' => $status, 'comments' => $comments, 'approver_id' => $u->id, 'approved_at' => now()]);

        return $this->approvalRow($u, $id);
    }

    private function approvalRow(User $u, string $id)
    {
        $s = $this->scope($u);
        $q = DB::table('approvals')->where('id', $id)->where('school_id', $s['school_id']);
        if ($s['role'] === 'HOD') {
            $q->where('approver_id', $u->id);
        }$x = $q->first();
        if (! $x) {
            throw new AuthorizationException('Approval is outside leadership scope.');
        }

        return $x;
    }

    public function teaching(User $u, string $type)
    {
        $s = $this->scope($u);
        if (! $s['academic']) {
            throw new AuthorizationException('Academic oversight denied.');
        }
        $table = $type === 'plans' ? 'lesson_plans' : 'records_of_work';
        $q = DB::table($table)->where($table.'.school_id', $s['school_id'])->where($table.'.is_deleted', false);
        if ($s['role'] === 'HOD') {
            $ids = DB::table('teacher_assignments')->where('school_id', $s['school_id'])->whereIn('learning_area_id', $s['learning_area_ids'])->pluck('id');
            if ($type === 'plans') {
                $q->whereIn('teacher_assignment_id', $ids);
            } else {
                $q->whereIn('lesson_plan_id', DB::table('lesson_plans')->whereIn('teacher_assignment_id', $ids)->pluck('id'));
            }
        }

        return $q->latest('created_at')->paginate(20);
    }

    public function workload(User $u)
    {
        $this->a->require($u, 'view_teacher_workload');
        $s = $this->scope($u);
        $q = DB::table('teacher_assignments')->where('school_id', $s['school_id'])->where('active', true)->where('is_deleted', false);
        if ($s['role'] === 'HOD') {
            $q->whereIn('learning_area_id', $s['learning_area_ids']);
        }

        return $q->selectRaw('teacher_id, COUNT(*) assignments, COALESCE(SUM(lessons_per_week),0) lessons_per_week')->groupBy('teacher_id')->get();
    }

    public function assessments(User $u)
    {
        $s = $this->scope($u);
        if (! $s['academic']) {
            throw new AuthorizationException('Assessment oversight denied.');
        }
        $q = DB::table('exams')->where('school_id', $s['school_id'])->where('is_deleted', false);

        return ['upcoming' => (clone $q)->where('start_date', '>=', today())->count(), 'published' => (clone $q)->where('status', 'published')->count()];
    }

    public function reports(User $u)
    {
        $s = $this->scope($u);
        if (! $s['academic']) {
            throw new AuthorizationException('Report-card oversight denied.');
        }
        $q = DB::table('report_cards')->where('school_id', $s['school_id'])->where('is_deleted', false);

        return ['generated' => (clone $q)->where('status', 'generated')->count(), 'published' => (clone $q)->where('status', 'published')->count(), 'awaiting_publication' => (clone $q)->where('status', 'generated')->count(), 'merit_lists' => DB::table('merit_lists')->where('school_id', $s['school_id'])->where('is_deleted', false)->count()];
    }

    public function academic(User $u)
    {
        $s = $this->scope($u);
        if (! $s['academic']) {
            throw new AuthorizationException('Academic summary denied.');
        }$q = DB::table('learning_area_results')->where('school_id', $s['school_id'])->where('is_deleted', false);
        if ($s['role'] === 'HOD') {
            $q->whereIn('learning_area_id', $s['learning_area_ids']);
        }

        return ['average_percentage' => round((float) $q->avg('percentage'), 2), 'processed_results' => $q->count()];
    }

    public function discipline(User $u)
    {
        $s = $this->scope($u);
        if (! $s['discipline']) {
            throw new AuthorizationException('Discipline summary denied.');
        }$q = DB::table('discipline_cases')->where('school_id', $s['school_id']);

        return ['open' => (clone $q)->whereRaw('LOWER(status)=?', ['open'])->count(), 'resolved' => (clone $q)->whereRaw('LOWER(status)=?', ['resolved'])->count()];
    }

    public function finance(User $u)
    {
        $s = $this->scope($u);
        if (! $s['finance']) {
            throw new AuthorizationException('Finance summary denied.');
        }$i = DB::table('fee_invoices')->where('school_id', $s['school_id'])->whereNull('cancelled_at')->whereRaw("LOWER(COALESCE(status,'')) <> 'cancelled'");
        $p = DB::table('payments')->where('school_id', $s['school_id'])->where('reversed', false);

        return ['invoiced' => (float) $i->sum('total_amount'), 'paid' => (float) $i->sum('amount_paid'), 'outstanding' => (float) $i->sum('balance'), 'collection_today' => (float) (clone $p)->whereDate('payment_date', today())->sum('amount'), 'recent_payments' => (clone $p)->latest('payment_date')->limit(10)->get()];
    }

    public function announcements(User $u)
    {
        if (Schema::hasTable('communications') && Schema::hasTable('communication_recipient_snapshots')) {
            return $this->communications->portalAnnouncements($u);
        }

        return DB::table('broadcasts')->where('school_id', $u->school_id)->whereRaw('LOWER(status)=?', ['sent'])->whereNotNull('sent_at')->latest('sent_at')->limit(20)->get();
    }

    public function notifications(User $u)
    {
        return DB::table('notifications')->where('school_id', $u->school_id)->where('user_id', $u->id)->latest('created_at')->paginate(20);
    }

    public function dashboard(User $u)
    {
        $s = $this->scope($u);
        $p = $this->preferences($u);
        $d = ['profile' => $this->profile($u), 'preferences' => $p];
        $map = ['show_attendance' => fn () => $s['attendance'] ? $this->attendance($u) : null, 'show_curriculum_coverage' => fn () => $s['curriculum'] ? $this->curriculum($u) : null, 'show_pending_approvals' => fn () => $s['approvals'] ? $this->approvals($u) : null, 'show_lesson_plans' => fn () => $s['academic'] ? $this->teaching($u, 'plans') : null, 'show_records_of_work' => fn () => $s['academic'] ? $this->teaching($u, 'records') : null, 'show_exams' => fn () => $s['academic'] ? $this->assessments($u) : null, 'show_report_cards' => fn () => $s['academic'] ? $this->reports($u) : null, 'show_academic_performance' => fn () => $s['academic'] ? $this->academic($u) : null, 'show_discipline' => fn () => $s['discipline'] ? $this->discipline($u) : null, 'show_finance' => fn () => $s['finance'] ? $this->finance($u) : null, 'show_announcements' => fn () => $this->announcements($u), 'show_notifications' => fn () => $this->notifications($u), 'show_teacher_workload' => fn () => $s['workload'] ? $this->workload($u) : null, 'show_learner_enrolment' => fn () => DB::table('learners')->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->count()];
        foreach ($map as $k => $fn) {
            if ($p->$k && ($v = $fn()) !== null) {
                $d[substr($k, 5)] = $v;
            }
        }

        return $d;
    }
}
