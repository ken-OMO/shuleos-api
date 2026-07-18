<?php

namespace App\Services\ParentPortal;

use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\User;
use App\Services\Attendance\AttendanceReadService;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Finance\FinancePortalService;
use App\Services\Homework\HomeworkParentService;
use App\Services\LearningResource\LearningResourceService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ParentPortalMobileService
{
    public function __construct(
        private ParentPortalAccessService $access,
        private ParentReportCardAccessService $reportCardAccess,
        private AttendanceReadService $attendance,
        private FinancePortalService $finance,
        private HomeworkParentService $homework,
        private LearningResourceService $learningResources,
        private CommunicationNotificationService $notifications,
    ) {}

    public function children(User $user): array
    {
        return $this->access->links($user)->map(fn ($link) => $this->child($link->learner, $link))->values()->all();
    }

    public function childProfile(User $user, string $learnerId): array
    {
        $learner = $this->access->requireLinkedLearner($user, $learnerId)->load('grade', 'stream');
        $link = $this->access->requireLink($user, $learnerId);
        $teacher = DB::table('teacher_assignments as assignment')
            ->join('teachers as teacher', 'teacher.id', '=', 'assignment.teacher_id')
            ->join('users as user', 'user.id', '=', 'teacher.user_id')
            ->where('assignment.school_id', $user->school_id)
            ->where('assignment.grade_id', $learner->grade_id)
            ->where('assignment.stream_id', $learner->stream_id)
            ->where('assignment.is_class_teacher', true)
            ->where('assignment.active', true)
            ->where('assignment.is_deleted', false)
            ->select('user.first_name', 'user.last_name')
            ->first();
        $learningAreas = DB::table('learning_area_allocations as allocation')
            ->join('learning_areas as area', 'area.id', '=', 'allocation.learning_area_id')
            ->where('allocation.school_id', $user->school_id)
            ->where('allocation.grade_id', $learner->grade_id)
            ->where('allocation.active', true)
            ->select('area.id', 'area.learning_area_name', 'area.short_name')
            ->orderBy('area.learning_area_name')
            ->get();
        $school = DB::table('schools')->where('id', $user->school_id)->select('school_name', 'school_code', 'website')->first();
        $period = $this->currentPeriod($user->school_id);

        return [
            'learner' => $this->child($learner, $link),
            'school' => $school,
            'class_teacher' => $teacher ? ['name' => trim($teacher->first_name.' '.$teacher->last_name)] : null,
            'learning_areas' => $learningAreas,
            'academic_year' => $period['academic_year'],
            'term' => $period['term'],
            'emergency_contact' => ['is_primary_contact' => (bool) $link->is_primary_contact, 'relationship' => $link->relationship],
            'documents' => ['available_count' => count($this->documents($user, $learnerId))],
        ];
    }

    public function dashboard(User $user, ?string $learnerId): array
    {
        $parent = $this->access->parent($user);
        $learner = $this->access->defaultLearner($user, $learnerId);
        $period = $this->currentPeriod($user->school_id);
        $finance = $this->safeWidget(fn () => $this->finance->linked($user, $learner->id));
        $attendance = $this->safeWidget(fn () => $this->attendance->summary($this->attendance->parent($user, $learner->id)));
        $homework = $this->safeWidget(fn () => DB::table('homework_assignment_learners as link')->join('homework_assignments as assignment', 'assignment.id', '=', 'link.assignment_id')->where('link.school_id', $user->school_id)->where('link.learner_id', $learner->id)->where('assignment.status', 'published')->where('assignment.is_deleted', false)->whereIn('link.submission_status', ['not_started', 'in_progress', 'returned'])->count());
        $payments = collect(data_get($finance, 'payments', []))->take(config('parent_portal.dashboard_recent_payments', 5))->values();
        $latestCard = $this->safeWidget(fn () => $this->reportCards($user, $learner->id)->first());
        $announcements = $this->safeWidget(fn () => $this->notifications->portalAnnouncements($user)->take(5)->values(), collect());
        $urgent = collect($announcements)->whereIn('priority', ['high', 'critical'])->count();

        return [
            'parent' => ['id' => $parent->id, 'name' => trim($parent->first_name.' '.$parent->last_name)],
            'children' => $this->children($user),
            'selected_child' => $this->child($learner, $this->access->requireLink($user, $learner->id)),
            'academic_year' => $period['academic_year'],
            'term' => $period['term'],
            'fee_balance' => data_get($finance, 'account.current_balance'),
            'recent_confirmed_payments' => $payments,
            'today_timetable' => $this->safeWidget(fn () => $this->timetable($user, $learner->id, now()->dayOfWeekIso)),
            'attendance_summary' => $attendance,
            'outstanding_homework_count' => $homework,
            'unread_notification_count' => $this->notifications->unreadCount($user),
            'current_announcements' => $announcements,
            'latest_published_report_card' => $latestCard,
            'upcoming_events' => array_slice($this->calendar($user, now()->toDateString(), now()->addDays(30)->toDateString(), $learner->id), 0, config('parent_portal.dashboard_upcoming_events', 10)),
            'urgent_communication_count' => $urgent,
            'contact_warnings' => $this->contactWarnings($user),
            'last_refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function attendanceQuery(User $user, string $learnerId, array $filters = [])
    {
        $query = $this->attendance->parent($user, $learnerId)
            ->when($filters['date_from'] ?? null, fn ($q, $value) => $q->whereDate('attendance_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->whereDate('attendance_date', '<=', $value))
            ->when($filters['academic_year_id'] ?? null, fn ($q, $value) => $q->where('academic_year_id', $value))
            ->when($filters['term_id'] ?? null, fn ($q, $value) => $q->where('term_id', $value));
        if ($filters['status'] ?? null) {
            $query->whereHas('attendanceStatus', fn ($q) => $q->whereRaw('UPPER(status_code) = ?', [strtoupper($filters['status'])]));
        }

        return $query;
    }

    public function attendanceSummary(User $user, string $learnerId, array $filters = []): array
    {
        return $this->attendance->summary($this->attendanceQuery($user, $learnerId, $filters));
    }

    public function timetable(User $user, string $learnerId, ?int $day = null): array
    {
        $learner = $this->access->requireLinkedLearner($user, $learnerId);
        $today = now()->toDateString();
        $entries = DB::table('timetable_entries as entry')
            ->join('timetables as timetable', 'timetable.id', '=', 'entry.timetable_id')
            ->leftJoin('timetable_periods as period', 'period.id', '=', 'entry.period_id')
            ->leftJoin('learning_areas as area', 'area.id', '=', 'entry.learning_area_id')
            ->leftJoin('teachers as teacher', 'teacher.id', '=', 'entry.teacher_id')
            ->leftJoin('users as teacher_user', 'teacher_user.id', '=', 'teacher.user_id')
            ->leftJoin('timetable_substitutions as substitution', function ($join) use ($today) {
                $join->on('substitution.timetable_entry_id', '=', 'entry.id')->whereDate('substitution.substitution_date', $today)->where('substitution.status', 'approved');
            })
            ->leftJoin('teachers as substitute_teacher', 'substitute_teacher.id', '=', 'substitution.substitute_teacher_id')
            ->leftJoin('users as substitute_user', 'substitute_user.id', '=', 'substitute_teacher.user_id')
            ->leftJoin('rooms as room', 'room.id', '=', 'entry.room_id')
            ->where('timetable.school_id', $user->school_id)
            ->where('timetable.status', 'published')
            ->where('timetable.active', true)
            ->where('timetable.is_deleted', false)
            ->where(fn ($q) => $q->whereNull('timetable.effective_from')->orWhereDate('timetable.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('timetable.effective_to')->orWhereDate('timetable.effective_to', '>=', $today))
            ->where('entry.grade_id', $learner->grade_id)
            ->where('entry.stream_id', $learner->stream_id)
            ->where('entry.is_deleted', false)
            ->when($day, fn ($q) => $q->where('entry.day_of_week', $day))
            ->select('entry.id', 'entry.day_of_week', 'period.period_name', 'period.start_time', 'period.end_time', 'area.learning_area_name', 'teacher_user.first_name', 'teacher_user.last_name', 'substitute_user.first_name as substitute_first_name', 'substitute_user.last_name as substitute_last_name', 'room.room_name')
            ->orderBy('entry.day_of_week')->orderBy('period.start_time')->get();

        return $entries->map(fn ($entry) => [
            'id' => $entry->id,
            'day' => $entry->day_of_week,
            'period' => $entry->period_name,
            'start_time' => $entry->start_time,
            'end_time' => $entry->end_time,
            'learning_area' => $entry->learning_area_name,
            'teacher' => trim(($entry->substitute_first_name ?: $entry->first_name).' '.($entry->substitute_last_name ?: $entry->last_name)) ?: null,
            'is_substitution' => (bool) $entry->substitute_first_name,
            'room' => $entry->room_name,
        ])->all();
    }

    public function homework(User $user, string $learnerId, array $filters = []): LengthAwarePaginator
    {
        $this->access->requireLinkedLearner($user, $learnerId);
        $perPage = $this->perPage($filters['per_page'] ?? 20);
        $query = DB::table('homework_assignment_learners as link')
            ->join('homework_assignments as assignment', 'assignment.id', '=', 'link.assignment_id')
            ->leftJoin('learning_areas as area', 'area.id', '=', 'assignment.learning_area_id')
            ->leftJoin('teachers as teacher', 'teacher.id', '=', 'assignment.teacher_id')
            ->leftJoin('users as teacher_user', 'teacher_user.id', '=', 'teacher.user_id')
            ->leftJoin('homework_submissions as submission', function ($join) use ($learnerId) {
                $join->on('submission.assignment_learner_id', '=', 'link.id')->where('submission.learner_id', $learnerId);
            })
            ->leftJoin('homework_submission_marks as mark', function ($join) {
                $join->on('mark.submission_id', '=', 'submission.id')->where('mark.status', 'released');
            })
            ->where('link.school_id', $user->school_id)->where('link.learner_id', $learnerId)
            ->where('assignment.status', 'published')->where('assignment.is_deleted', false)
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('link.submission_status', $value))
            ->when($filters['due_from'] ?? null, fn ($q, $value) => $q->whereDate('assignment.due_at', '>=', $value))
            ->when($filters['due_to'] ?? null, fn ($q, $value) => $q->whereDate('assignment.due_at', '<=', $value))
            ->when($filters['learning_area_id'] ?? null, fn ($q, $value) => $q->where('assignment.learning_area_id', $value))
            ->select('assignment.id', 'assignment.title', 'assignment.instructions', 'assignment.published_at as assigned_at', 'assignment.due_at', 'area.learning_area_name', 'link.submission_status', 'submission.is_late', 'mark.final_score', 'mark.percentage', 'mark.competency_level', 'mark.teacher_feedback', 'mark.released_at', 'teacher_user.first_name as teacher_first_name', 'teacher_user.last_name as teacher_last_name')
            ->orderByDesc('assignment.due_at');

        return $query->paginate($perPage)->through(fn ($row) => $this->safeHomework($row));
    }

    public function homeworkItem(User $user, string $learnerId, string $assignmentId): array
    {
        $page = $this->homework($user, $learnerId, ['per_page' => config('parent_portal.pagination_max', 50)]);
        $row = collect($page->items())->firstWhere('id', $assignmentId);
        abort_unless($row, 404);

        return $row;
    }

    public function learningResources(User $user, string $learnerId)
    {
        $learner = $this->access->requireLinkedLearner($user, $learnerId);

        return $this->learningResources->publishedForLearner($user->school_id, $learner->grade_id, $learner->stream_id, ['parents'])->with($this->learningResources->relations());
    }

    public function results(User $user, string $learnerId, ?string $examId = null): array
    {
        $this->access->requireLinkedLearner($user, $learnerId);
        $exams = DB::table('learning_area_results as result')
            ->join('exams as exam', 'exam.id', '=', 'result.exam_id')
            ->join('learning_areas as area', 'area.id', '=', 'result.learning_area_id')
            ->leftJoin('grading_scales as scale', 'scale.id', '=', 'result.grading_scale_id')
            ->where('result.school_id', $user->school_id)->where('result.learner_id', $learnerId)
            ->where('result.processing_status', 'processed')->where('result.is_deleted', false)
            ->where('exam.status', 'published')->where('exam.is_deleted', false)
            ->when($examId, fn ($q) => $q->where('exam.id', $examId))
            ->select('exam.id as exam_id', 'exam.exam_name', 'result.id', 'area.learning_area_name', 'result.marks_obtained', 'result.maximum_marks', 'result.percentage', 'scale.grade_code', 'scale.grade_description', 'scale.points', 'result.processed_at')
            ->orderByDesc('exam.end_date')->orderBy('area.learning_area_name')->limit(200)->get();
        if ($examId) {
            abort_if($exams->isEmpty(), 404);
        }

        return $exams->groupBy('exam_id')->map(fn ($rows) => [
            'exam' => ['id' => $rows->first()->exam_id, 'name' => $rows->first()->exam_name],
            'learning_areas' => $rows->map(fn ($row) => collect((array) $row)->except(['exam_id', 'exam_name'])->all())->values(),
            'total_score' => round((float) $rows->sum('marks_obtained'), 2),
            'maximum_marks' => round((float) $rows->sum('maximum_marks'), 2),
            'average_percentage' => round((float) $rows->avg('percentage'), 2),
        ])->values()->all();
    }

    public function reportCards(User $user, string $learnerId)
    {
        $this->access->requireLinkedLearner($user, $learnerId);

        return ReportCard::current()->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('status', 'published')->with('exam', 'term', 'overallGradingScale')->latest('published_at')->limit(20)->get()->map(function ($card) use ($user, $learnerId) {
            $decision = $this->reportCardAccess->decision($user, $learnerId, $card->id);

            return ['report_card' => $card, 'access' => collect($decision)->except('report_card')->all()];
        });
    }

    public function finance(User $user, string $learnerId, string $section = 'summary'): mixed
    {
        $data = $this->finance->linked($user, $learnerId);

        return match ($section) {
            'summary' => collect($data)->only(['available', 'account'])->all(),
            'invoices' => collect($data['invoices'])->take(config('parent_portal.pagination_max', 50))->values(),
            'payments' => collect($data['payments'])->take(config('parent_portal.pagination_max', 50))->values(),
            'statement' => $this->boundedStatement(data_get($this->finance->linkedBenefits($user, $learnerId), 'statement', ['available' => false])),
            default => $data,
        };
    }

    public function communications(User $user, ?string $communicationId = null): mixed
    {
        $this->access->parent($user);
        $query = DB::table('communications as communication')
            ->join('communication_recipient_snapshots as recipient', function ($join) use ($user) {
                $join->on('recipient.communication_id', '=', 'communication.id')->where('recipient.user_id', $user->id);
            })
            ->leftJoin('users as sender', 'sender.id', '=', 'communication.sender_user_id')
            ->leftJoin('schools as school', 'school.id', '=', 'communication.school_id')
            ->leftJoin('communication_branding as branding', 'branding.school_id', '=', 'communication.school_id')
            ->leftJoin('notifications as notification', function ($join) use ($user) {
                $join->on('notification.communication_id', '=', 'communication.id')->where('notification.user_id', $user->id);
            })
            ->where('communication.school_id', $user->school_id)->where('recipient.school_id', $user->school_id)->where('communication.status', 'sent')
            ->when($communicationId, fn ($q) => $q->where('communication.id', $communicationId))
            ->select('communication.id', 'communication.subject', 'communication.body', 'communication.priority', 'communication.category', 'communication.sent_at', 'notification.state', 'notification.read_at', 'sender.first_name as sender_first_name', 'sender.last_name as sender_last_name', 'school.school_name', 'branding.sender_display_name as branding_sender_name')
            ->distinct()->latest('communication.sent_at');
        if ($communicationId) {
            return $query->firstOrFail();
        }

        return $query->paginate(30);
    }

    public function calendar(User $user, string $from, string $to, ?string $learnerId = null): array
    {
        $learner = $learnerId ? $this->access->requireLinkedLearner($user, $learnerId) : null;
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        abort_if($start->diffInDays($end) > config('parent_portal.calendar_max_days', 90), 422, 'Calendar range exceeds the allowed maximum.');
        $events = collect();
        DB::table('terms')->where('school_id', $user->school_id)->where('active', true)->whereDate('end_date', '>=', $start)->whereDate('start_date', '<=', $end)->get()->each(function ($term) use ($events) {
            $events->push($this->event('term', $term->id, $term->term_name, $term->start_date, $term->end_date, true));
        });
        DB::table('exams')->where('school_id', $user->school_id)->whereIn('status', ['published', 'completed'])->where('is_deleted', false)->whereDate('end_date', '>=', $start)->whereDate('start_date', '<=', $end)->get()->each(function ($exam) use ($events, $learner) {
            $events->push($this->event('exam', $exam->id, $exam->exam_name, $exam->start_date, $exam->end_date, true, $learner?->id));
        });
        if ($learner) {
            DB::table('homework_assignment_learners as link')->join('homework_assignments as assignment', 'assignment.id', '=', 'link.assignment_id')->where('link.school_id', $user->school_id)->where('link.learner_id', $learner->id)->where('assignment.status', 'published')->where('assignment.is_deleted', false)->whereBetween('assignment.due_at', [$start, $end])->select('assignment.id', 'assignment.title', 'assignment.due_at')->get()->each(function ($homework) use ($events, $learner) {
                $events->push($this->event('homework_due', $homework->id, $homework->title, $homework->due_at, $homework->due_at, false, $learner->id, '/parent/children/'.$learner->id.'/homework/'.$homework->id));
            });
        }

        return $events->unique(fn ($event) => $event['type'].'|'.$event['id'].'|'.$event['start'])->sortBy('start')->values()->all();
    }

    public function documents(User $user, string $learnerId): array
    {
        return $this->reportCards($user, $learnerId)->map(fn ($row) => [
            'id' => 'report_card_'.$row['report_card']->id,
            'type' => 'report_card',
            'title' => ($row['report_card']->exam?->exam_name ?? 'Published').' Report Card',
            'mime_type' => 'application/pdf',
            'published_at' => $row['report_card']->published_at,
            'download_allowed' => (bool) $row['access']['can_download'],
            'lock_reason' => $row['access']['can_download'] ? null : ($row['access']['restriction_message'] ?: 'Report card download is restricted.'),
        ])->values()->all();
    }

    public function documentReportCardId(User $user, string $learnerId, string $documentId): string
    {
        abort_unless(str_starts_with($documentId, 'report_card_'), 404);
        $id = substr($documentId, strlen('report_card_'));
        $this->reportCardAccess->requireDownload($user, $learnerId, $id);

        return $id;
    }

    private function currentPeriod(string $schoolId): array
    {
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('active', true)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->select('id', 'year_name', 'start_date', 'end_date')->first();
        $term = DB::table('terms')->where('school_id', $schoolId)->when($year, fn ($q) => $q->where('academic_year_id', $year->id))->where('active', true)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->select('id', 'term_name', 'start_date', 'end_date')->first();

        return ['academic_year' => $year, 'term' => $term];
    }

    private function child(Learner $learner, object $link): array
    {
        return [
            'id' => $learner->id,
            'admission_number' => $learner->admission_no,
            'full_name' => trim($learner->first_name.' '.$learner->middle_name.' '.$learner->last_name),
            'grade' => $learner->grade ? ['id' => $learner->grade->id, 'name' => $learner->grade->grade_name] : null,
            'stream' => $learner->stream ? ['id' => $learner->stream->id, 'name' => $learner->stream->stream_name] : null,
            'enrolment_status' => $learner->active ? 'active' : 'inactive',
            'relationship' => $link->relationship,
            'is_primary_guardian' => (bool) $link->is_primary_contact,
            'portal_access' => (bool) $link->portal_enabled,
        ];
    }

    private function safeHomework(object $row): array
    {
        return [
            'id' => $row->id, 'title' => $row->title, 'learning_area' => $row->learning_area_name, 'instructions' => $row->instructions,
            'assigned_at' => $row->assigned_at, 'due_at' => $row->due_at, 'submission_status' => $row->submission_status,
            'is_late' => (bool) $row->is_late, 'teacher' => trim($row->teacher_first_name.' '.$row->teacher_last_name) ?: null,
            'released_result' => $row->released_at ? ['score' => $row->final_score, 'percentage' => $row->percentage, 'competency_level' => $row->competency_level, 'feedback' => $row->teacher_feedback, 'released_at' => $row->released_at] : null,
        ];
    }

    private function event(string $type, string $id, string $title, mixed $start, mixed $end, bool $allDay, ?string $learnerId = null, ?string $deepLink = null): array
    {
        return ['id' => $id, 'type' => $type, 'title' => $title, 'start' => $start, 'end' => $end, 'all_day' => $allDay, 'learner_id' => $learnerId, 'priority' => 'normal', 'deep_link' => $deepLink];
    }

    private function contactWarnings(User $user): array
    {
        $warnings = [];
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'Email address is missing or requires verification.';
        }
        if (blank($user->phone)) {
            $warnings[] = 'Phone number is missing.';
        }

        return $warnings;
    }

    private function perPage(int|string $value): int
    {
        return max(1, min((int) $value, config('parent_portal.pagination_max', 50)));
    }

    private function boundedStatement(mixed $statement): mixed
    {
        if (! is_array($statement) || ! isset($statement['entries'])) {
            return $statement;
        }
        $entries = collect($statement['entries']);
        $statement['entries'] = $entries->take(config('parent_portal.pagination_max', 50))->values();
        $statement['entries_truncated'] = $entries->count() > config('parent_portal.pagination_max', 50);

        return $statement;
    }

    private function safeWidget(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            report($exception);

            return $fallback;
        }
    }
}
