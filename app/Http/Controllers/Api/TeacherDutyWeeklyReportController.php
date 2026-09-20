<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\OpenTeacherDutyWeeklyReportRequest;
use App\Http\Requests\TeacherDuty\ReviewTeacherDutyWeeklyReportRequest;
use App\Http\Requests\TeacherDuty\SubmitTeacherDutyWeeklyReportRequest;
use App\Http\Requests\TeacherDuty\TeacherDutyWeeklyReportStateRequest;
use App\Http\Requests\TeacherDuty\UpdateTeacherDutyWeeklyReportRequest;
use App\Models\TeacherDutyPeriod;
use App\Models\TeacherDutyWeeklyReport;
use App\Services\TeacherDuty\TeacherDutyAuthorizationService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeacherDutyWeeklyReportController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Weekly Reports';

    public function __construct(
        private readonly TeacherDutyWeeklyReportService $service,
        private readonly TeacherDutyAuthorizationService $authorization
    ) {}

    public function open(
        OpenTeacherDutyWeeklyReportRequest $request,
        string $period
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);
        $periodId = $this->teacherDutyPeriodId(
            $schoolId,
            $period
        );

        $this->authorization->reporter(
            $schoolId,
            $periodId,
            $actorId
        );

        $existing = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $periodId)
            ->first();

        $report = $this->teacherDutyOperation(
            fn () => $this->service->openReport(
                $schoolId,
                $periodId,
                $actorId
            )
        );

        if ($existing) {
            return response()->json([
                'data' => $this->weeklyReportResource($report),
            ]);
        }

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $report,
            null,
            $this->weeklyReportAuditValues($report),
            'Opened Teacher duty weekly report.'
        );

        return response()->json([
            'message' => 'Teacher duty weekly report opened successfully.',
            'data' => $this->weeklyReportResource($report),
        ], 201);
    }

    public function state(
        TeacherDutyWeeklyReportStateRequest $request,
        string $report
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyWeeklyReport(
            $schoolId,
            $report
        );

        /*
         * Weekly state is intentionally a dual-authority read.
         * The service composes reporter responsibility for the concrete
         * period OR school-level reviewer authority.
         */
        $state = $this->teacherDutyOperation(
            fn () => $this->service->state(
                $schoolId,
                (string) $reportModel->id,
                $actorId
            )
        );

        return response()->json([
            'data' => [
                'state' => $state['state'],
                'current_narrative' => $state['current_narrative'],
                'current_daily_completeness' => $state['current_daily_completeness'],
                'current_occurrence_aggregates' => $state['current_occurrence_aggregates'],
                'submission_evidence' => $state['submission_evidence'],
                'review_evidence' => $state['review_evidence'],
                'latest_submission_evidence_snapshot' => $state['latest_submission_evidence_snapshot'],
                'history' => $state['history'],
            ],
        ]);
    }

    public function update(
        UpdateTeacherDutyWeeklyReportRequest $request,
        string $report
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyWeeklyReport(
            $schoolId,
            $report
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $reportModel->duty_period_id,
            $actorId
        );

        $oldValues = $this->weeklyNarrativeValues($reportModel);

        $updated = $this->teacherDutyOperation(
            fn () => $this->service->updateReport(
                $schoolId,
                (string) $reportModel->id,
                $validated['summary'],
                $validated['highlights'],
                $validated['challenges'],
                $validated['recommendations'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $updated,
            $oldValues,
            $this->weeklyNarrativeValues($updated),
            'Updated Teacher duty weekly report.'
        );

        return response()->json([
            'message' => 'Teacher duty weekly report updated successfully.',
            'data' => $this->weeklyReportResource($updated),
        ]);
    }

    public function submit(
        SubmitTeacherDutyWeeklyReportRequest $request,
        string $report
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyWeeklyReport(
            $schoolId,
            $report
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $reportModel->duty_period_id,
            $actorId
        );

        $oldValues = $this->weeklyLifecycleValues($reportModel);

        $submitted = $this->teacherDutyOperation(
            fn () => $this->service->submitReport(
                $schoolId,
                (string) $reportModel->id,
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $submitted,
            $oldValues,
            $this->weeklyLifecycleValues($submitted),
            'Submitted Teacher duty weekly report.'
        );

        return response()->json([
            'message' => 'Teacher duty weekly report submitted successfully.',
            'data' => $this->weeklyReportResource($submitted),
        ]);
    }

    public function resubmit(
        SubmitTeacherDutyWeeklyReportRequest $request,
        string $report
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyWeeklyReport(
            $schoolId,
            $report
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $reportModel->duty_period_id,
            $actorId
        );

        $oldValues = $this->weeklyLifecycleValues($reportModel);

        $resubmitted = $this->teacherDutyOperation(
            fn () => $this->service->resubmitReport(
                $schoolId,
                (string) $reportModel->id,
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $resubmitted,
            $oldValues,
            $this->weeklyLifecycleValues($resubmitted),
            'Resubmitted Teacher duty weekly report.'
        );

        return response()->json([
            'message' => 'Teacher duty weekly report resubmitted successfully.',
            'data' => $this->weeklyReportResource($resubmitted),
        ]);
    }

    public function review(
        ReviewTeacherDutyWeeklyReportRequest $request,
        string $report
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyWeeklyReport(
            $schoolId,
            $report
        );

        $this->authorization->reviewer(
            $schoolId,
            $actorId
        );

        $oldValues = $this->weeklyLifecycleValues($reportModel);

        $reviewed = $this->teacherDutyOperation(
            fn () => $this->service->reviewReport(
                $schoolId,
                (string) $reportModel->id,
                (string) $validated['decision'],
                $validated['comment'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $reviewed,
            $oldValues,
            $this->weeklyLifecycleValues($reviewed),
            'Reviewed Teacher duty weekly report.'
        );

        return response()->json([
            'message' => 'Teacher duty weekly report reviewed successfully.',
            'data' => $this->weeklyReportResource($reviewed),
        ]);
    }

    private function teacherDutyPeriodId(
        string $schoolId,
        string $period
    ): string {
        $periodId = $this->teacherDutyResourceId($period);

        $periodModel = $this->teacherDutyOperation(
            fn () => TeacherDutyPeriod::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereKey($periodId)
                ->firstOrFail()
        );

        return (string) $periodModel->id;
    }

    private function teacherDutyWeeklyReport(
        string $schoolId,
        string $report
    ): TeacherDutyWeeklyReport {
        $reportId = $this->teacherDutyResourceId($report);

        return $this->teacherDutyOperation(
            fn () => TeacherDutyWeeklyReport::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereKey($reportId)
                ->firstOrFail()
        );
    }

    private function teacherDutyResourceId(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages([
                'resource' => [
                    'The requested teacher duty resource is unavailable.',
                ],
            ]);
        }

        return $id;
    }

    private function teacherDutyOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'resource' => [
                    'The requested teacher duty resource is unavailable.',
                ],
            ]);
        }
    }

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->school_id, 403);

        $schoolId = (string) $user->school_id;
        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId === null
            || (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403);
        }

        return $schoolId;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);

        return (string) $user->id;
    }

    private function weeklyReportResource(
        TeacherDutyWeeklyReport $report
    ): array {
        return [
            'id' => $report->id,
            'duty_period_id' => $report->duty_period_id,
            'status' => $report->status,
            'summary' => $report->summary,
            'highlights' => $report->highlights,
            'challenges' => $report->challenges,
            'recommendations' => $report->recommendations,
            'evidence_snapshot' => $report->evidence_snapshot,
            'created_by' => $report->created_by,
            'submitted_by' => $report->submitted_by,
            'submitted_at' => $report->submitted_at,
            'reviewed_by' => $report->reviewed_by,
            'reviewed_at' => $report->reviewed_at,
            'review_comment' => $report->review_comment,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    private function weeklyReportAuditValues(
        TeacherDutyWeeklyReport $report
    ): array {
        return [
            'duty_period_id' => $report->duty_period_id,
            'status' => $report->status,
            'summary' => $report->summary,
            'highlights' => $report->highlights,
            'challenges' => $report->challenges,
            'recommendations' => $report->recommendations,
        ];
    }

    private function weeklyNarrativeValues(
        TeacherDutyWeeklyReport $report
    ): array {
        return [
            'summary' => $report->summary,
            'highlights' => $report->highlights,
            'challenges' => $report->challenges,
            'recommendations' => $report->recommendations,
        ];
    }

    private function weeklyLifecycleValues(
        TeacherDutyWeeklyReport $report
    ): array {
        return [
            'status' => $report->status,
            'submitted_by' => $report->submitted_by,
            'submitted_at' => $report->submitted_at,
            'reviewed_by' => $report->reviewed_by,
            'reviewed_at' => $report->reviewed_at,
            'review_comment' => $report->review_comment,
        ];
    }
}
