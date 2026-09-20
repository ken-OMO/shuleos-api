<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\OpenTeacherDutyDailyReportRequest;
use App\Http\Requests\TeacherDuty\SubmitTeacherDutyDailyReportRequest;
use App\Http\Requests\TeacherDuty\TeacherDutyDailyReportStateRequest;
use App\Http\Requests\TeacherDuty\UpdateTeacherDutyDailyReportRequest;
use App\Models\TeacherDutyDailyReport;
use App\Models\TeacherDutyPeriod;
use App\Services\TeacherDuty\TeacherDutyAuthorizationService;
use App\Services\TeacherDuty\TeacherDutyDailyReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeacherDutyDailyReportController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Daily Reports';

    public function __construct(
        private readonly TeacherDutyDailyReportService $service,
        private readonly TeacherDutyAuthorizationService $authorization
    ) {}

    public function open(
        OpenTeacherDutyDailyReportRequest $request,
        string $period
    ): JsonResponse {
        $validated = $request->validated();
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

        $reportDate = (string) $validated['report_date'];

        $existing = TeacherDutyDailyReport::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $periodId)
            ->get(['id', 'report_date'])
            ->first(
                fn (TeacherDutyDailyReport $candidate): bool => $candidate->report_date?->format('Y-m-d') === $reportDate
            );

        $report = $this->teacherDutyOperation(
            fn () => $this->service->openReport(
                $schoolId,
                $periodId,
                $reportDate,
                $actorId
            )
        );

        if ($existing) {
            return response()->json([
                'data' => $this->dailyReportResource($report),
            ]);
        }

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $report,
            null,
            $this->dailyReportAuditValues($report),
            'Opened Teacher duty daily report.'
        );

        return response()->json([
            'message' => 'Teacher duty daily report opened successfully.',
            'data' => $this->dailyReportResource($report),
        ], 201);
    }

    public function state(
        TeacherDutyDailyReportStateRequest $request,
        string $period
    ): JsonResponse {
        $validated = $request->validated();
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

        $state = $this->teacherDutyOperation(
            fn () => $this->service->state(
                $schoolId,
                $periodId,
                (string) $validated['report_date'],
                $actorId
            )
        );

        return response()->json([
            'data' => [
                'state' => $state['state'],
                'deadline_at' => $state['deadline_at'],
                'submitted_at' => $state['submitted_at'],
                'late' => $state['late'],
            ],
        ]);
    }

    public function update(
        UpdateTeacherDutyDailyReportRequest $request,
        string $report
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyDailyReport(
            $schoolId,
            $report
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $reportModel->duty_period_id,
            $actorId
        );

        $oldValues = [
            'summary' => $reportModel->summary,
        ];

        $updated = $this->teacherDutyOperation(
            fn () => $this->service->updateDraft(
                $schoolId,
                (string) $reportModel->id,
                $validated['summary'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $updated,
            $oldValues,
            [
                'summary' => $updated->summary,
            ],
            'Updated Teacher duty daily report.'
        );

        return response()->json([
            'message' => 'Teacher duty daily report updated successfully.',
            'data' => $this->dailyReportResource($updated),
        ]);
    }

    public function submit(
        SubmitTeacherDutyDailyReportRequest $request,
        string $report
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $reportModel = $this->teacherDutyDailyReport(
            $schoolId,
            $report
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $reportModel->duty_period_id,
            $actorId
        );

        $oldValues = [
            'status' => $reportModel->status,
            'submitted_at' => $reportModel->submitted_at,
        ];

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
            [
                'status' => $submitted->status,
                'submitted_at' => $submitted->submitted_at,
            ],
            'Submitted Teacher duty daily report.'
        );

        return response()->json([
            'message' => 'Teacher duty daily report submitted successfully.',
            'data' => $this->dailyReportResource($submitted),
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

    private function teacherDutyDailyReport(
        string $schoolId,
        string $report
    ): TeacherDutyDailyReport {
        $reportId = $this->teacherDutyResourceId($report);

        return $this->teacherDutyOperation(
            fn () => TeacherDutyDailyReport::query()
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

    private function dailyReportResource(
        TeacherDutyDailyReport $report
    ): array {
        return [
            'id' => $report->id,
            'duty_period_id' => $report->duty_period_id,
            'report_date' => $report->report_date?->format('Y-m-d'),
            'status' => $report->status,
            'summary' => $report->summary,
            'deadline_at' => $report->deadline_at,
            'created_by' => $report->created_by,
            'submitted_by' => $report->submitted_by,
            'submitted_at' => $report->submitted_at,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    private function dailyReportAuditValues(
        TeacherDutyDailyReport $report
    ): array {
        return [
            'duty_period_id' => $report->duty_period_id,
            'report_date' => $report->report_date?->format('Y-m-d'),
            'status' => $report->status,
            'summary' => $report->summary,
            'deadline_at' => $report->deadline_at,
        ];
    }
}
