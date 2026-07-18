<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LeadershipAcademicSummaryResource;
use App\Http\Resources\LeadershipActionResource;
use App\Http\Resources\LeadershipAlertResource;
use App\Http\Resources\LeadershipApprovalResource;
use App\Http\Resources\LeadershipApprovalSummaryResource;
use App\Http\Resources\LeadershipAttendanceSummaryResource;
use App\Http\Resources\LeadershipBehaviourSummaryResource;
use App\Http\Resources\LeadershipCommunicationSummaryResource;
use App\Http\Resources\LeadershipDashboardResource;
use App\Http\Resources\LeadershipDeviceResource;
use App\Http\Resources\LeadershipFinanceSummaryResource;
use App\Http\Resources\LeadershipHodAnalyticsResource;
use App\Http\Resources\LeadershipKpiResource;
use App\Http\Resources\LeadershipPreferenceResource;
use App\Http\Resources\LeadershipReportPreviewResource;
use App\Http\Resources\LeadershipTeacherComplianceResource;
use App\Http\Resources\LeadershipTeacherSummaryResource;
use App\Http\Resources\LeadershipTimetableSummaryResource;
use App\Services\LeadershipPortal\LeadershipApprovalCentreService;
use App\Services\LeadershipPortal\LeadershipDeviceService;
use App\Services\LeadershipPortal\LeadershipPortalPhaseTwoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadershipPortalPhaseTwoController extends BaseApiController
{
    public function __construct(
        private LeadershipPortalPhaseTwoService $portal,
        private LeadershipApprovalCentreService $approvals,
        private LeadershipDeviceService $devices,
    ) {}

    public function dashboard(?string $view = null)
    {
        return $this->success(new LeadershipDashboardResource($this->portal->dashboard(auth()->user(), $view)));
    }

    public function approvals()
    {
        return $this->success(new LeadershipApprovalSummaryResource($this->approvals->index(auth()->user())));
    }

    public function approvalSummary()
    {
        return $this->success(new LeadershipApprovalSummaryResource($this->approvals->summary(auth()->user())));
    }

    public function approval(string $approval)
    {
        return $this->success(new LeadershipApprovalResource($this->approvals->show(auth()->user(), $approval)));
    }

    public function decideApproval(Request $request, string $approval, string $action)
    {
        $data = $request->validate(['reason' => [Rule::requiredIf(in_array($action, ['changes_requested', 'rejected'], true)), 'nullable', 'string', 'max:2000']]);

        return $this->success(new LeadershipApprovalResource($this->approvals->decide(auth()->user(), $approval, $action, $data['reason'] ?? null)));
    }

    public function teachers()
    {
        return $this->success(LeadershipTeacherSummaryResource::collection($this->portal->teachers(auth()->user())));
    }

    public function teacher(string $teacher)
    {
        return $this->success(new LeadershipTeacherSummaryResource($this->portal->teacher(auth()->user(), $teacher)));
    }

    public function teacherMetric(string $teacher, string $metric)
    {
        return $this->success(new LeadershipTeacherComplianceResource($this->portal->teacherMetrics(auth()->user(), $teacher, $metric)));
    }

    public function kpis(bool $trends = false)
    {
        return $this->success(new LeadershipKpiResource($this->portal->kpis(auth()->user(), $trends)));
    }

    public function academic(string $view, ?string $id = null)
    {
        return $this->success(new LeadershipAcademicSummaryResource($this->portal->academic(auth()->user(), $view, $id)));
    }

    public function attendance(string $view, ?string $id = null)
    {
        return $this->success(new LeadershipAttendanceSummaryResource($this->portal->attendance(auth()->user(), $view, $id)));
    }

    public function behaviour(string $view, ?string $id = null)
    {
        return $this->success(new LeadershipBehaviourSummaryResource($this->portal->behaviour(auth()->user(), $view, $id)));
    }

    public function finance(string $view)
    {
        return $this->success(new LeadershipFinanceSummaryResource($this->portal->finance(auth()->user(), $view)));
    }

    public function communications(string $view)
    {
        return $this->success(new LeadershipCommunicationSummaryResource($this->portal->communications(auth()->user(), $view)));
    }

    public function timetable(string $view)
    {
        return $this->success(new LeadershipTimetableSummaryResource($this->portal->timetable(auth()->user(), $view)));
    }

    public function actions()
    {
        return $this->success(LeadershipActionResource::collection($this->portal->actions(auth()->user())));
    }

    public function alerts()
    {
        return $this->success(LeadershipAlertResource::collection($this->portal->alerts(auth()->user())));
    }

    public function alertState(string $alert, string $state)
    {
        return $this->success(new LeadershipAlertResource($this->portal->changeAlertState(auth()->user(), $alert, $state)));
    }

    public function hod(string $view)
    {
        return $this->success(new LeadershipHodAnalyticsResource($this->portal->hod(auth()->user(), $view)));
    }

    public function reports()
    {
        return $this->success(new LeadershipReportPreviewResource($this->portal->reportDefinitions(auth()->user())));
    }

    public function report(Request $request, bool $generate = false)
    {
        $data = $request->validate(['report' => ['required', 'string'], 'days' => ['sometimes', 'integer', 'min:1'], 'filters' => ['sometimes', 'array', 'max:10']]);

        return $this->success(new LeadershipReportPreviewResource($this->portal->report(auth()->user(), $data, $generate)));
    }

    public function preferences()
    {
        return $this->success(new LeadershipPreferenceResource($this->portal->preferences(auth()->user())));
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'default_role_view' => ['sometimes', 'nullable', Rule::in(['principal', 'deputy', 'hod', 'director'])],
            'preferred_grade_id' => ['sometimes', 'nullable', 'uuid'],
            'preferred_learning_area_id' => ['sometimes', 'nullable', 'uuid'],
            'timezone' => ['sometimes', 'timezone:all'],
            'language' => ['sometimes', 'string', 'max:12'],
            'dashboard_widgets' => ['sometimes', 'array', 'max:30'],
            'dashboard_widgets.*' => ['string', 'max:80'],
            'notification_preferences' => ['sometimes', 'array', 'max:20'],
            'digest_frequency' => ['sometimes', Rule::in(['never', 'daily', 'weekly'])],
            'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'default_date_range_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'kpi_widget_order' => ['sometimes', 'array', 'max:30'],
            'kpi_widget_order.*' => ['string', 'max:80'],
        ]);

        return $this->success(new LeadershipPreferenceResource($this->portal->updatePreferences(auth()->user(), $data)));
    }

    public function devices()
    {
        return $this->success(LeadershipDeviceResource::collection($this->devices->index(auth()->user())));
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'device_identifier' => ['required', 'string', 'min:8', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web', 'pwa'])],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'push_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);

        return $this->created(new LeadershipDeviceResource($this->devices->register(auth()->user(), $data)));
    }

    public function revokeDevice(string $device)
    {
        $this->devices->revoke(auth()->user(), $device);

        return $this->success(null, 'Leadership device revoked.');
    }
}
