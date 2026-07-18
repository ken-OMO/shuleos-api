<?php

namespace App\Services\LeadershipPortal;

use App\Models\LeadershipAlertState;
use App\Models\LeadershipDashboardPreference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadershipPortalPhaseTwoService
{
    private const REPORTS = [
        'attendance_summary', 'fee_collection_summary', 'teacher_compliance', 'curriculum_coverage',
        'academic_performance', 'marks_readiness', 'communication_delivery', 'timetable_coverage',
        'behaviour_summary', 'school_kpi',
    ];

    public function __construct(
        private LeadershipPortalAccessService $access,
        private LeadershipPortalAuditService $audit,
    ) {}

    public function dashboard(User $user, ?string $requestedView = null): array
    {
        $scope = $this->access->scope($user);
        $view = $requestedView ?: $this->defaultView($scope['role_key']);
        $permission = match ($view) {
            'principal' => 'view_principal_dashboard',
            'deputy' => 'view_deputy_dashboard',
            'hod' => 'view_hod_dashboard',
            'director' => 'view_director_dashboard',
            default => throw ValidationException::withMessages(['dashboard' => 'Unsupported leadership dashboard.']),
        };
        $this->access->require($user, $permission);
        if ($view === 'hod' && $scope['role_key'] !== 'hod' && ! $scope['whole_school']) {
            throw new AuthorizationException('HOD dashboard scope is unavailable.');
        }

        $widgets = match ($view) {
            'hod' => [
                'department' => $this->hod($user, 'dashboard'),
                'teacher_compliance' => $this->teacherComplianceSummary($user),
                'academic_readiness' => $this->academic($user, 'readiness'),
                'alerts' => array_slice($this->alerts($user), 0, 10),
            ],
            'director' => [
                'kpis' => $this->kpis($user),
                'attendance_trend' => $this->attendance($user, 'trends'),
                'finance' => $scope['finance'] ? $this->finance($user, 'summary') : null,
                'operational_health' => $this->operationalHealth($user),
            ],
            'deputy' => [
                'attendance' => $scope['attendance'] ? $this->attendance($user, 'today') : null,
                'behaviour' => $scope['discipline'] ? $this->behaviour($user, 'summary') : null,
                'teacher_compliance' => $this->teacherComplianceSummary($user),
                'actions' => array_slice($this->actions($user), 0, 20),
            ],
            default => [
                'kpis' => $this->kpis($user),
                'academic_readiness' => $scope['academic'] ? $this->academic($user, 'readiness') : null,
                'finance' => $scope['finance'] ? $this->finance($user, 'summary') : null,
                'teacher_compliance' => $this->teacherComplianceSummary($user),
                'actions' => array_slice($this->actions($user), 0, 20),
                'alerts' => array_slice($this->alerts($user), 0, 10),
            ],
        };

        $this->audit->record($user, 'dashboard_viewed', null, null, ['view' => $view]);

        return [
            'role_view' => $view,
            'academic_context' => $this->academicContext($scope['school_id']),
            'widgets' => array_filter($widgets, fn ($value) => $value !== null),
            'last_refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function teachers(User $user)
    {
        $this->access->require($user, 'view_teacher_compliance');
        $query = DB::table('teachers as teachers')
            ->join('users', 'users.id', '=', 'teachers.user_id')
            ->where('teachers.school_id', $user->school_id)
            ->where('teachers.active', true)
            ->where('teachers.is_deleted', false)
            ->select(['teachers.id', 'users.first_name', 'users.last_name', 'teachers.designation']);
        $this->access->applyTeacherScope($query, $user, 'teachers.id');

        return $query->orderBy('users.first_name')->paginate(config('leadership_portal_phase_two.page_size', 20));
    }

    public function teacher(User $user, string $teacherId): array
    {
        $this->access->require($user, 'view_teacher_compliance');
        $this->access->assertTeacher($user, $teacherId);
        $teacher = DB::table('teachers as teachers')
            ->join('users', 'users.id', '=', 'teachers.user_id')
            ->where('teachers.id', $teacherId)
            ->where('teachers.school_id', $user->school_id)
            ->select(['teachers.id', 'users.first_name', 'users.last_name', 'teachers.designation', 'teachers.employment_type'])
            ->firstOrFail();
        $this->audit->record($user, 'teacher_summary_viewed', 'teacher', $teacherId);

        return ['teacher' => $teacher, 'compliance' => $this->teacherMetrics($user, $teacherId, 'compliance')];
    }

    public function teacherMetrics(User $user, string $teacherId, string $metric): array
    {
        $this->access->assertTeacher($user, $teacherId);
        $this->access->require($user, $metric === 'workload' ? 'view_teacher_workload' : 'view_teacher_compliance');
        $assignments = DB::table('teacher_assignments')
            ->where('school_id', $user->school_id)
            ->where('teacher_id', $teacherId)
            ->where('active', true)
            ->where('is_deleted', false);
        $assignmentIds = (clone $assignments)->pluck('id');

        return match ($metric) {
            'workload' => [
                'active_assignments' => (clone $assignments)->count(),
                'lessons_per_week' => (int) (clone $assignments)->sum('lessons_per_week'),
            ],
            'teaching-workflows', 'compliance' => $this->workflowCounts($user->school_id, $teacherId),
            'attendance-submissions' => $this->attendanceSubmissions($user->school_id, $teacherId),
            'homework-activity' => $this->homeworkActivity($user->school_id, $teacherId),
            'marks-progress' => $this->marksProgress($user->school_id, $teacherId, $assignmentIds->all()),
            default => throw ValidationException::withMessages(['metric' => 'Unsupported teacher metric.']),
        };
    }

    public function kpis(User $user, bool $trends = false): array
    {
        $this->access->require($user, 'view_school_kpis');
        $scope = $this->access->scope($user);
        $learners = DB::table('learners')->where('school_id', $scope['school_id'])->where('active', true)->where('is_deleted', false)->count();
        $teacherQuery = DB::table('teachers')->where('school_id', $scope['school_id'])->where('active', true)->where('is_deleted', false);
        $this->access->applyTeacherScope($teacherQuery, $user);
        $teachers = $teacherQuery->count();
        $attendance = $scope['attendance'] ? $this->attendanceSummary($user) : null;
        $resultCount = DB::table('learning_area_results')->where('school_id', $scope['school_id'])->where('is_deleted', false)->where('processing_status', 'processed')->count();
        $reportCount = DB::table('report_cards')->where('school_id', $scope['school_id'])->where('is_deleted', false)->whereIn('status', ['generated', 'published'])->count();
        $data = [
            'definitions' => [
                'attendance_rate' => 'Present and late finalized attendance marks divided by all finalized marks.',
                'teacher_workflow_compliance' => 'Approved workflows divided by all submitted or reviewed workflows.',
                'learner_teacher_ratio' => 'Active learners divided by active teachers in scope.',
            ],
            'enrolment' => $scope['role_key'] === 'hod' ? null : $learners,
            'active_learners' => $scope['role_key'] === 'hod' ? null : $learners,
            'teacher_count' => $teachers,
            'learner_teacher_ratio' => $scope['role_key'] === 'hod' || $teachers === 0 ? null : round($learners / $teachers, 2),
            'daily_attendance_rate' => $attendance['rate'] ?? null,
            'academic_results_processed' => $resultCount,
            'report_cards_ready' => $reportCount,
            'teacher_workflow_compliance' => $this->teacherComplianceSummary($user),
            'unresolved_approvals' => $this->approvalCount($user),
        ];
        if ($scope['finance']) {
            $data['finance'] = $this->finance($user, 'summary');
        }
        if ($trends) {
            $data = ['range_days' => $this->boundedRangeDays(request()->integer('days', 30)), 'attendance' => $this->attendance($user, 'trends')];
        }

        return array_filter($data, fn ($value) => $value !== null);
    }

    public function academic(User $user, string $view, ?string $id = null): array
    {
        $this->access->require($user, 'view_academic_insights');
        $scope = $this->access->scope($user);
        $results = DB::table('learning_area_results as results')
            ->where('results.school_id', $scope['school_id'])
            ->where('results.is_deleted', false)
            ->where('results.processing_status', 'processed');
        if ($scope['role_key'] === 'hod') {
            $results->whereIn('results.learning_area_id', $scope['learning_area_ids']);
        }
        if ($view === 'grade' && $id) {
            $this->assertTenantId('grades', $id, $scope['school_id']);
            $results->join('learners', 'learners.id', '=', 'results.learner_id')->where('learners.grade_id', $id);
        }
        if ($view === 'stream' && $id) {
            $this->assertTenantId('streams', $id, $scope['school_id']);
            $results->join('learners', 'learners.id', '=', 'results.learner_id')->where('learners.stream_id', $id);
        }
        if ($view === 'learning-area' && $id) {
            $this->access->assertLearningArea($user, $id);
            $results->where('results.learning_area_id', $id);
        }

        $summary = [
            'processed_results' => (clone $results)->count(),
            'average_percentage' => $this->nullableRound((clone $results)->avg('percentage')),
            'minimum_percentage' => $this->nullableRound((clone $results)->min('percentage')),
            'maximum_percentage' => $this->nullableRound((clone $results)->max('percentage')),
        ];

        return match ($view) {
            'grades' => ['items' => $this->academicGroups($results, 'learners.grade_id', 'grade_id')],
            'learning-areas' => ['items' => $this->academicGroups($results, 'results.learning_area_id', 'learning_area_id')],
            'readiness' => $summary + $this->readiness($scope['school_id']),
            'interventions' => ['threshold' => (float) request()->input('threshold', 50), 'counts_only' => true, 'below_threshold' => (clone $results)->where('percentage', '<', (float) request()->input('threshold', 50))->count()],
            default => $summary,
        };
    }

    public function attendance(User $user, string $view, ?string $id = null): array
    {
        $this->access->require($user, 'view_attendance_intelligence');
        $scope = $this->access->scope($user);
        $query = DB::table('learner_attendance as attendance')
            ->join('attendance_statuses as statuses', 'statuses.id', '=', 'attendance.attendance_status_id')
            ->where('attendance.school_id', $scope['school_id'])
            ->where('attendance.finalized', true);
        $this->applyAttendanceScope($query, $scope);
        if ($view === 'today') {
            $query->whereDate('attendance.attendance_date', today());
        }
        if ($view === 'grade' && $id) {
            $this->assertTenantId('grades', $id, $scope['school_id']);
            $query->where('attendance.grade_id', $id);
        }
        if ($view === 'stream' && $id) {
            $this->assertTenantId('streams', $id, $scope['school_id']);
            $query->where('attendance.stream_id', $id);
        }
        if ($view === 'learner' && $id) {
            if ($scope['role_key'] === 'hod' || $scope['executive_summary_only']) {
                throw new AuthorizationException('Learner attendance detail is outside this leadership role scope.');
            }
            $this->access->require($user, 'view_attendance_analytics');
            $this->assertTenantId('learners', $id, $scope['school_id']);
            $query->where('attendance.learner_id', $id);
            $this->audit->record($user, 'learner_attendance_viewed', 'learner', $id);
        }
        if ($view === 'trends') {
            $days = $this->boundedRangeDays(request()->integer('days', 30));
            $rows = $query->whereDate('attendance.attendance_date', '>=', today()->subDays($days - 1))
                ->selectRaw('attendance.attendance_date AS date, COUNT(*) AS total, SUM(CASE WHEN UPPER(statuses.status_code) IN (?, ?) THEN 1 ELSE 0 END) AS attended', ['P', 'L'])
                ->groupBy('attendance.attendance_date')->orderBy('attendance.attendance_date')->limit($days)->get();

            return ['range_days' => $days, 'items' => $rows->map(fn ($row) => ['date' => $row->date, 'rate' => $row->total ? round(((int) $row->attended / (int) $row->total) * 100, 2) : null])];
        }
        if ($view === 'alerts') {
            return ['items' => array_values(array_filter($this->alerts($user), fn ($alert) => $alert['module'] === 'attendance'))];
        }

        return $this->attendanceSummaryFrom($query);
    }

    public function behaviour(User $user, string $view, ?string $id = null): array
    {
        $this->access->require($user, 'view_behaviour_oversight');
        $scope = $this->access->scope($user);
        $query = DB::table('discipline_cases')->where('school_id', $scope['school_id'])->where('is_deleted', false);
        if ($view === 'case' && $id) {
            $case = (clone $query)->whereKey($id)->select(['id', 'incident_date', 'location', 'status', 'severity', 'priority', 'reviewed_at', 'resolved_at'])->first();
            abort_unless($case, 404);
            $this->audit->record($user, 'behaviour_case_viewed', 'discipline_case', $id);

            return ['case' => $case];
        }

        return [
            'open' => (clone $query)->whereNotIn(DB::raw('LOWER(status)'), ['resolved', 'closed'])->count(),
            'resolved' => (clone $query)->whereIn(DB::raw('LOWER(status)'), ['resolved', 'closed'])->count(),
            'escalated' => (clone $query)->whereIn('severity', ['high', 'critical'])->count(),
            'recognitions' => Schema::hasTable('behaviour_recognitions') ? DB::table('behaviour_recognitions')->where('school_id', $scope['school_id'])->count() : 0,
            'view' => $view,
        ];
    }

    public function finance(User $user, string $view): array
    {
        $this->access->require($user, 'view_finance_oversight');
        $invoices = DB::table('fee_invoices')->where('school_id', $user->school_id)->whereNull('cancelled_at')->whereRaw("LOWER(COALESCE(status,'')) <> 'cancelled'");
        $payments = DB::table('payments')->where('school_id', $user->school_id)->where('reversed', false);
        $summary = [
            'invoiced' => round((float) (clone $invoices)->sum('total_amount'), 2),
            'paid' => round((float) (clone $invoices)->sum('amount_paid'), 2),
            'outstanding' => round((float) (clone $invoices)->sum('balance'), 2),
            'collections' => round((float) (clone $payments)->sum('amount'), 2),
        ];
        $summary['collection_rate'] = $summary['invoiced'] > 0 ? round(($summary['paid'] / $summary['invoiced']) * 100, 2) : null;
        $this->audit->record($user, 'finance_summary_viewed', null, null, ['view' => $view]);

        return match ($view) {
            'arrears' => ['outstanding' => $summary['outstanding'], 'overdue_invoices' => (clone $invoices)->whereDate('due_date', '<', today())->where('balance', '>', 0)->count()],
            'payment-plans' => ['active' => Schema::hasTable('payment_plans') ? DB::table('payment_plans')->where('school_id', $user->school_id)->where('status', 'active')->count() : 0],
            'refunds' => ['pending' => Schema::hasTable('payment_refunds') ? DB::table('payment_refunds')->where('school_id', $user->school_id)->where('status', 'pending')->count() : 0],
            'adjustments' => ['pending' => DB::table('finance_adjustments')->where('school_id', $user->school_id)->where('status', 'submitted')->count()],
            'sms-wallet' => $this->smsWallet($user->school_id),
            default => $summary,
        };
    }

    public function communications(User $user, string $view): array
    {
        $this->access->require($user, 'view_communication_monitoring');
        $scope = $this->access->scope($user);
        $communications = DB::table('communications')->where('school_id', $scope['school_id']);
        if ($scope['role_key'] === 'hod') {
            $communications->where('sender_user_id', $user->id);
        }
        $deliveries = DB::table('communication_deliveries')->where('school_id', $scope['school_id']);
        if ($scope['role_key'] === 'hod') {
            $deliveries->whereIn('communication_id', (clone $communications)->pluck('id'));
        }

        return match ($view) {
            'pending-approvals' => ['count' => (clone $communications)->where('status', 'pending_approval')->count()],
            'failures' => ['count' => (clone $deliveries)->where('status', 'failed')->count()],
            'emergencies' => ['count' => (clone $communications)->where('communication_type', 'emergency')->count()],
            'sms-usage' => $this->access->has($user, 'view_finance_oversight') ? $this->smsWallet($scope['school_id']) : throw new AuthorizationException('SMS financial usage requires finance oversight permission.'),
            'delivery-health' => [
                'delivered' => (clone $deliveries)->whereIn('status', ['sent', 'accepted', 'delivered'])->count(),
                'failed' => (clone $deliveries)->where('status', 'failed')->count(),
                'provider_dependent_metrics' => true,
            ],
            default => [
                'sent' => (clone $communications)->where('status', 'sent')->count(),
                'scheduled' => (clone $communications)->where('status', 'scheduled')->count(),
                'failed_deliveries' => (clone $deliveries)->where('status', 'failed')->count(),
            ],
        };
    }

    public function timetable(User $user, string $view): array
    {
        $this->access->require($user, 'view_timetable_oversight');
        $scope = $this->access->scope($user);
        $timetables = DB::table('timetables')->where('school_id', $scope['school_id'])->where('is_deleted', false)->whereIn('status', ['published', 'approved']);
        $ids = (clone $timetables)->pluck('id');
        $entries = DB::table('timetable_entries')->where('school_id', $scope['school_id'])->whereIn('timetable_id', $ids)->where('is_deleted', false);
        if ($scope['role_key'] === 'hod') {
            $entries->whereIn('learning_area_id', $scope['learning_area_ids']);
        }

        return match ($view) {
            'conflicts' => ['count' => Schema::hasTable('timetable_conflicts') ? DB::table('timetable_conflicts')->where('school_id', $scope['school_id'])->whereIn('status', ['open', 'unresolved'])->count() : 0, 'explainable_only' => true],
            'substitutions' => ['active' => DB::table('timetable_substitutions')->where('school_id', $scope['school_id'])->where('status', 'approved')->whereDate('substitution_date', '>=', today())->count()],
            'uncovered-lessons' => ['count' => (clone $entries)->whereNull('teacher_id')->count()],
            'teacher-workload' => ['assigned_lessons' => (clone $entries)->whereNotNull('teacher_id')->count()],
            default => ['current_timetables' => $ids->count(), 'published_entries' => $entries->count(), 'solver_internals_exposed' => false],
        };
    }

    public function actions(User $user): array
    {
        $this->access->require($user, 'view_leadership_action_queue');
        $scope = $this->access->scope($user);
        $items = [];
        $this->pushAction($items, 'pending_approval', $this->approvalCount($user), 'Pending approvals', 'high', 'approvals', $scope['role_key']);
        if ($scope['attendance']) {
            $missing = DB::table('attendance_registers')->where('school_id', $scope['school_id'])->whereDate('attendance_date', '<=', today())->where('status', '!=', 'finalized')->count();
            $this->pushAction($items, 'missing_attendance_register', $missing, 'Attendance registers require finalization', 'high', 'attendance', $scope['role_key']);
        }
        $workflow = $this->teacherComplianceSummary($user);
        $this->pushAction($items, 'overdue_teacher_workflow', $workflow['pending_review'], 'Teacher workflows await review', 'warning', 'teacher_workflow', $scope['role_key']);
        if ($this->access->has($user, 'view_communication_monitoring')) {
            $failed = $this->communications($user, 'failures')['count'];
            $this->pushAction($items, 'failed_communication', $failed, 'Communication deliveries failed', 'warning', 'communication', $scope['role_key']);
        }

        return array_values(collect($items)->unique('key')->sortByDesc(fn ($item) => ['critical' => 4, 'high' => 3, 'warning' => 2, 'info' => 1][$item['priority']])->take(50)->all());
    }

    public function alerts(User $user): array
    {
        $this->access->require($user, 'view_leadership_alerts');
        $scope = $this->access->scope($user);
        $alerts = [];
        if ($scope['attendance']) {
            $rate = $this->attendanceSummary($user)['rate'];
            $threshold = config('leadership_portal_phase_two.low_attendance_threshold', 85);
            if ($rate !== null && $rate < $threshold) {
                $alerts[] = $this->alert('attendance-low', 'attendance', 'high', 'Finalized attendance is below the configured threshold.', $rate, $threshold);
            }
        }
        if ($scope['academic']) {
            $missing = $this->readiness($scope['school_id'])['missing_raw_results'];
            if ($missing > 0) {
                $alerts[] = $this->alert('marks-missing', 'academic', 'warning', 'Exam papers have missing raw results.', $missing, 0);
            }
        }
        if ($this->access->has($user, 'view_communication_monitoring')) {
            $failed = $this->communications($user, 'failures')['count'];
            if ($failed > 0) {
                $alerts[] = $this->alert('communication-failures', 'communication', 'warning', 'Communication deliveries have failed.', $failed, 0);
            }
        }
        $states = LeadershipAlertState::withoutGlobalScopes()->where('school_id', $scope['school_id'])->where('user_id', $user->id)->pluck('state', 'alert_key');

        return collect($alerts)->map(function ($alert) use ($states) {
            $alert['state'] = $states[$alert['key']] ?? 'open';

            return $alert;
        })->filter(fn ($alert) => $alert['state'] !== 'dismissed')->values()->all();
    }

    public function changeAlertState(User $user, string $key, string $state): array
    {
        $this->access->require($user, 'acknowledge_leadership_alerts');
        $alert = collect($this->alerts($user))->firstWhere('key', $key);
        abort_unless($alert, 404);
        LeadershipAlertState::withoutGlobalScopes()->updateOrCreate(
            ['school_id' => $user->school_id, 'user_id' => $user->id, 'alert_key' => $key],
            ['id' => (string) Str::uuid(), 'state' => $state, 'acted_at' => now()]
        );
        $this->audit->record($user, 'alert_'.$state, 'leadership_alert', null, ['alert_key' => $key]);
        $alert['state'] = $state;

        return $alert;
    }

    public function hod(User $user, string $view): array
    {
        $this->access->require($user, 'view_hod_department_analytics');
        $scope = $this->access->scope($user);
        if ($scope['role_key'] !== 'hod' && ! $scope['whole_school']) {
            throw new AuthorizationException('Departmental analytics scope required.');
        }
        $assignments = DB::table('teacher_assignments')->where('school_id', $scope['school_id'])->whereIn('learning_area_id', $scope['learning_area_ids'] ?: ['00000000-0000-0000-0000-000000000000'])->where('active', true)->where('is_deleted', false);
        $base = [
            'learning_area_ids' => $scope['learning_area_ids'],
            'teacher_count' => (clone $assignments)->distinct()->count('teacher_id'),
            'assignment_count' => (clone $assignments)->count(),
            'finance_included' => false,
        ];

        return match ($view) {
            'teachers' => $base + ['teachers' => array_values($scope['teacher_ids'])],
            'compliance' => $base + ['compliance' => $this->teacherComplianceSummary($user)],
            'curriculum-coverage' => $base + ['coverage' => $this->curriculumCoverage($scope)],
            'marks' => $base + ['marks' => $this->marksReadinessForAssignments((clone $assignments)->pluck('id')->all())],
            'homework' => $base + ['homework' => $this->homeworkForAssignments((clone $assignments)->pluck('id')->all())],
            'resources' => $base + ['resources' => $this->resourcesForAreas($scope['school_id'], $scope['learning_area_ids'])],
            'communications' => $base + ['communications' => $this->communications($user, 'summary')],
            default => $base + ['compliance' => $this->teacherComplianceSummary($user), 'coverage' => $this->curriculumCoverage($scope)],
        };
    }

    public function reportDefinitions(User $user): array
    {
        $this->access->require($user, 'view_leadership_reports');

        return ['reports' => self::REPORTS, 'maximum_rows' => config('leadership_portal_phase_two.max_report_rows', 500), 'synchronous_file_export' => false];
    }

    public function report(User $user, array $data, bool $generate): array
    {
        $this->access->require($user, $generate ? 'generate_leadership_reports' : 'view_leadership_reports');
        if (! in_array($data['report'], self::REPORTS, true)) {
            throw ValidationException::withMessages(['report' => 'Unsupported report definition.']);
        }
        $days = $this->boundedRangeDays((int) ($data['days'] ?? 30));
        $reportData = match ($data['report']) {
            'attendance_summary' => $this->attendance($user, 'summary'),
            'fee_collection_summary' => $this->finance($user, 'summary'),
            'teacher_compliance' => $this->teacherComplianceSummary($user),
            'curriculum_coverage' => $this->curriculumCoverage($this->access->scope($user)),
            'academic_performance', 'marks_readiness' => $this->academic($user, 'readiness'),
            'communication_delivery' => $this->communications($user, 'delivery-health'),
            'timetable_coverage' => $this->timetable($user, 'coverage'),
            'behaviour_summary' => $this->behaviour($user, 'summary'),
            'school_kpi' => $this->kpis($user),
        };
        $this->audit->record($user, $generate ? 'report_generation_requested' : 'report_previewed', 'leadership_report', null, ['report' => $data['report'], 'days' => $days]);

        return ['report' => $data['report'], 'range_days' => $days, 'data' => $reportData, 'row_limit' => config('leadership_portal_phase_two.max_report_rows', 500), 'file_generation_deferred' => $generate];
    }

    public function preferences(User $user): LeadershipDashboardPreference
    {
        $this->access->require($user, 'manage_leadership_preferences');

        return LeadershipDashboardPreference::withoutGlobalScopes()->firstOrCreate(
            ['school_id' => $user->school_id, 'user_id' => $user->id],
            ['id' => (string) Str::uuid(), 'timezone' => 'Africa/Nairobi', 'language' => 'en']
        );
    }

    public function updatePreferences(User $user, array $data): LeadershipDashboardPreference
    {
        $scope = $this->access->scope($user);
        if (($data['default_role_view'] ?? null) === 'hod' && $scope['role_key'] !== 'hod' && ! $scope['whole_school']) {
            throw new AuthorizationException('HOD dashboard view is outside leadership scope.');
        }
        if (! empty($data['preferred_grade_id'])) {
            $this->assertTenantId('grades', $data['preferred_grade_id'], $scope['school_id']);
        }
        if (! empty($data['preferred_learning_area_id'])) {
            $this->access->assertLearningArea($user, $data['preferred_learning_area_id']);
        }
        $preference = $this->preferences($user);
        $preference->update($data);
        $this->audit->record($user, 'preferences_updated', 'leadership_dashboard_preference', $preference->id, ['fields' => array_keys($data)]);

        return $preference->fresh();
    }

    private function attendanceSummary(User $user): array
    {
        $query = DB::table('learner_attendance as attendance')
            ->join('attendance_statuses as statuses', 'statuses.id', '=', 'attendance.attendance_status_id')
            ->where('attendance.school_id', $user->school_id)
            ->where('attendance.finalized', true)
            ->whereDate('attendance.attendance_date', today());
        $this->applyAttendanceScope($query, $this->access->scope($user));

        return $this->attendanceSummaryFrom($query);
    }

    private function attendanceSummaryFrom(Builder $query): array
    {
        $rows = $query->selectRaw('UPPER(statuses.status_code) AS code, COUNT(*) AS total')->groupBy('statuses.status_code')->pluck('total', 'code');
        $present = (int) ($rows['P'] ?? $rows['PRESENT'] ?? 0);
        $absent = (int) ($rows['A'] ?? $rows['ABSENT'] ?? 0);
        $late = (int) ($rows['L'] ?? $rows['LATE'] ?? 0);
        $excused = (int) ($rows['E'] ?? $rows['EXCUSED'] ?? 0);
        $total = $present + $absent + $late + $excused;

        return ['present' => $present, 'absent' => $absent, 'late' => $late, 'excused' => $excused, 'finalized_denominator' => $total, 'rate' => $total ? round((($present + $late) / $total) * 100, 2) : null];
    }

    private function applyAttendanceScope(Builder $query, array $scope): void
    {
        if ($scope['role_key'] !== 'hod') {
            return;
        }
        $classes = DB::table('teacher_assignments')
            ->where('school_id', $scope['school_id'])
            ->whereIn('learning_area_id', $scope['learning_area_ids'])
            ->where('active', true)
            ->where('is_deleted', false)
            ->select(['grade_id', 'stream_id'])
            ->distinct()
            ->get();
        $query->where(function (Builder $bounded) use ($classes) {
            foreach ($classes as $class) {
                $bounded->orWhere(function (Builder $match) use ($class) {
                    $match->where('attendance.grade_id', $class->grade_id);
                    if ($class->stream_id) {
                        $match->where('attendance.stream_id', $class->stream_id);
                    }
                });
            }
            if ($classes->isEmpty()) {
                $bounded->whereRaw('1 = 0');
            }
        });
    }

    private function teacherComplianceSummary(User $user): array
    {
        $scope = $this->access->scope($user);
        $query = DB::table('teacher_workflows')->where('school_id', $scope['school_id']);
        if ($scope['role_key'] === 'hod') {
            $query->whereIn('teacher_id', $scope['teacher_ids']);
        }
        $submitted = (clone $query)->whereIn('state', ['submitted', 'approved', 'changes_requested'])->count();
        $approved = (clone $query)->where('state', 'approved')->count();

        return [
            'submitted_or_reviewed' => $submitted,
            'approved' => $approved,
            'changes_requested' => (clone $query)->where('state', 'changes_requested')->count(),
            'pending_review' => (clone $query)->where('state', 'submitted')->count(),
            'percentage' => $submitted ? round(($approved / $submitted) * 100, 2) : null,
            'public_rank' => null,
        ];
    }

    private function workflowCounts(string $schoolId, string $teacherId): array
    {
        $query = DB::table('teacher_workflows')->where('school_id', $schoolId)->where('teacher_id', $teacherId);

        return ['draft' => (clone $query)->where('state', 'draft')->count(), 'submitted' => (clone $query)->where('state', 'submitted')->count(), 'approved' => (clone $query)->where('state', 'approved')->count(), 'changes_requested' => (clone $query)->where('state', 'changes_requested')->count()];
    }

    private function attendanceSubmissions(string $schoolId, string $teacherId): array
    {
        $query = DB::table('attendance_registers')->where('school_id', $schoolId)->where('teacher_id', $teacherId)->where('is_deleted', false);

        return ['total' => (clone $query)->count(), 'finalized' => (clone $query)->where('status', 'finalized')->count(), 'open' => (clone $query)->where('status', '!=', 'finalized')->count()];
    }

    private function homeworkActivity(string $schoolId, string $teacherId): array
    {
        if (! Schema::hasTable('homework_assignments')) {
            return ['published' => 0, 'draft' => 0];
        }
        $query = DB::table('homework_assignments')->where('school_id', $schoolId)->where('teacher_id', $teacherId)->where('is_deleted', false);

        return ['published' => (clone $query)->where('status', 'published')->count(), 'draft' => (clone $query)->where('status', 'draft')->count()];
    }

    private function marksProgress(string $schoolId, string $teacherId, array $assignmentIds): array
    {
        $query = DB::table('mark_entry_batches')->where('school_id', $schoolId)->where('teacher_id', $teacherId)->whereIn('teacher_assignment_id', $assignmentIds);

        return ['batches' => (clone $query)->count(), 'submitted' => (clone $query)->whereIn('status', ['submitted', 'approved', 'locked'])->count(), 'expected_learners' => (int) (clone $query)->sum('expected_learner_count'), 'entered_marks' => (int) (clone $query)->sum('entered_count')];
    }

    private function readiness(string $schoolId): array
    {
        $papers = DB::table('exam_papers')->where('school_id', $schoolId)->where('is_deleted', false)->count();
        $results = DB::table('exam_results')->where('school_id', $schoolId)->where('is_deleted', false)->count();
        $processed = DB::table('learning_area_results')->where('school_id', $schoolId)->where('is_deleted', false)->where('processing_status', 'processed')->count();

        return ['exam_papers' => $papers, 'raw_results' => $results, 'processed_results' => $processed, 'missing_raw_results' => max(0, $papers - $results), 'deterministic' => true];
    }

    private function academicGroups(Builder $query, string $column, string $alias)
    {
        if (str_starts_with($column, 'learners.') && ! collect($query->joins ?? [])->contains(fn ($join) => $join->table === 'learners')) {
            $query->join('learners', 'learners.id', '=', 'results.learner_id');
        }

        return $query->selectRaw($column.' AS '.$alias.', COUNT(*) AS processed_results, ROUND(AVG(results.percentage), 2) AS average_percentage')->groupBy($column)->orderBy($column)->limit(100)->get();
    }

    private function curriculumCoverage(array $scope): array
    {
        $query = DB::table('curriculum_coverage')->where('school_id', $scope['school_id'])->where('is_deleted', false);
        if ($scope['role_key'] === 'hod') {
            $assignmentIds = DB::table('teacher_assignments')->where('school_id', $scope['school_id'])->whereIn('learning_area_id', $scope['learning_area_ids'])->pluck('id');
            $query->whereIn('teacher_assignment_id', $assignmentIds);
        }
        $total = (clone $query)->count();
        $complete = (clone $query)->where('completed', true)->count();

        return ['total' => $total, 'completed' => $complete, 'percentage' => $total ? round(($complete / $total) * 100, 2) : null];
    }

    private function marksReadinessForAssignments(array $assignmentIds): array
    {
        $query = DB::table('mark_entry_batches')->whereIn('teacher_assignment_id', $assignmentIds);

        return ['batches' => (clone $query)->count(), 'pending_moderation' => (clone $query)->where('status', 'submitted')->count(), 'approved' => (clone $query)->whereIn('status', ['approved', 'locked'])->count()];
    }

    private function homeworkForAssignments(array $assignmentIds): array
    {
        if (! Schema::hasTable('homework_assignments')) {
            return ['published' => 0];
        }
        $query = DB::table('homework_assignments')->whereIn('teacher_assignment_id', $assignmentIds)->where('is_deleted', false);

        return ['published' => (clone $query)->where('status', 'published')->count(), 'total' => (clone $query)->count()];
    }

    private function resourcesForAreas(string $schoolId, array $learningAreaIds): array
    {
        if (! Schema::hasTable('learning_resources')) {
            return ['published' => 0];
        }
        $query = DB::table('learning_resources')->where('school_id', $schoolId)->whereIn('learning_area_id', $learningAreaIds)->where('is_deleted', false);

        return ['published' => (clone $query)->where('status', 'published')->count(), 'total' => (clone $query)->count()];
    }

    private function approvalCount(User $user): int
    {
        $scope = $this->access->scope($user);
        $count = DB::table('teacher_workflows')->where('school_id', $scope['school_id'])->where('state', 'submitted');
        if ($scope['role_key'] === 'hod') {
            $count->whereIn('teacher_id', $scope['teacher_ids']);
        }

        return $count->count()
            + DB::table('mark_entry_batches')->where('school_id', $scope['school_id'])->where('status', 'submitted')->count()
            + DB::table('communications')->where('school_id', $scope['school_id'])->where('status', 'pending_approval')->count();
    }

    private function operationalHealth(User $user): array
    {
        return ['open_actions' => count($this->actions($user)), 'open_alerts' => count($this->alerts($user)), 'private_learner_details_included' => false];
    }

    private function smsWallet(string $schoolId): array
    {
        if (! Schema::hasTable('communication_sms_wallets')) {
            return ['available' => false];
        }
        $wallet = DB::table('communication_sms_wallets')->where('school_id', $schoolId)->first();

        return ['available' => (bool) $wallet, 'balance' => $wallet?->balance_credits ?? 0];
    }

    private function academicContext(string $schoolId): array
    {
        return [
            'academic_year' => DB::table('academic_years')->where('school_id', $schoolId)->where('active', true)->select(['id', 'year_name'])->first(),
            'term' => DB::table('terms')->where('school_id', $schoolId)->where('active', true)->select(['id', 'term_name'])->first(),
        ];
    }

    private function alert(string $key, string $module, string $severity, string $reason, int|float $value, int|float $threshold): array
    {
        return ['key' => $key, 'module' => $module, 'severity' => $severity, 'reason' => $reason, 'value' => $value, 'threshold' => $threshold, 'state' => 'open'];
    }

    private function pushAction(array &$items, string $type, int $count, string $title, string $priority, string $module, string $assigneeRole): void
    {
        if ($count < 1) {
            return;
        }
        $items[] = ['key' => $type, 'action_type' => $type, 'title' => $title, 'count' => $count, 'priority' => $priority, 'due_date' => null, 'module' => $module, 'entity_reference' => null, 'assignee_role' => $assigneeRole, 'status' => 'open', 'deep_link' => '/leadership/'.$module];
    }

    private function assertTenantId(string $table, string $id, string $schoolId): void
    {
        if (! DB::table($table)->whereKey($id)->where('school_id', $schoolId)->exists()) {
            throw new AuthorizationException('Resource is outside leadership tenant scope.');
        }
    }

    private function boundedRangeDays(int $days): int
    {
        return max(1, min($days, config('leadership_portal_phase_two.max_date_range_days', 366)));
    }

    private function nullableRound(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function defaultView(string $role): string
    {
        return match ($role) {
            'hod' => 'hod',
            'deputy_principal', 'deputy_headteacher' => 'deputy',
            'director', 'school_owner', 'finance_officer', 'finance_manager' => 'director',
            default => 'principal',
        };
    }
}
