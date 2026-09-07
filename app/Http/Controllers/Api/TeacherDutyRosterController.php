<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\EndTeacherDutyAssignmentRequest;
use App\Http\Requests\TeacherDuty\EndTeacherDutyPeriodRequest;
use App\Http\Requests\TeacherDuty\StoreTeacherDutyAssignmentRequest;
use App\Http\Requests\TeacherDuty\StoreTeacherDutyPeriodRequest;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeacherDutyRosterController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Roster';

    public function __construct(
        private readonly TeacherDutyRosterService $service
    ) {}

    public function currentPeriods(Request $request): JsonResponse
    {
        $periods = $this->teacherDutyOperation(
            fn () => $this->service->currentPeriods(
                $this->schoolId($request)
            )
        );

        return response()->json([
            'data' => $periods
                ->map(fn (TeacherDutyPeriod $period): array => $this->periodResource($period))
                ->values(),
        ]);
    }

    public function storePeriod(
        StoreTeacherDutyPeriodRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $period = $this->teacherDutyOperation(
            fn () => $this->service->createPeriod(
                $schoolId,
                (string) $validated['start_date'],
                (string) $validated['end_date'],
                isset($validated['academic_week_id'])
                    ? (string) $validated['academic_week_id']
                    : null,
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $period,
            null,
            $this->periodAuditValues($period),
            'Created Teacher duty period.'
        );

        return response()->json([
            'message' => 'Teacher duty period created successfully.',
            'data' => $this->periodResource($period),
        ], 201);
    }

    public function periodHistory(Request $request): JsonResponse
    {
        $periods = $this->teacherDutyOperation(
            fn () => $this->service->periodHistory(
                $this->schoolId($request)
            )
        );

        return response()->json([
            'data' => $periods
                ->map(fn (TeacherDutyPeriod $period): array => $this->periodResource($period))
                ->values(),
        ]);
    }

    public function showPeriod(
        Request $request,
        string $period
    ): JsonResponse {
        $periodModel = $this->teacherDutyOperation(
            fn () => $this->service->period(
                $this->schoolId($request),
                $this->teacherDutyResourceId($period)
            )
        );

        return response()->json([
            'data' => $this->periodResource($periodModel),
        ]);
    }

    public function endPeriod(
        EndTeacherDutyPeriodRequest $request,
        string $period
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);
        $validated = $request->validated();

        $before = $this->teacherDutyOperation(
            fn () => $this->service->period(
                $schoolId,
                $this->teacherDutyResourceId($period)
            )
        );

        $oldValues = $this->periodAuditValues($before);

        $ended = $this->teacherDutyOperation(
            fn () => $this->service->endPeriod(
                $schoolId,
                $this->teacherDutyResourceId($period),
                $actorId,
                isset($validated['reason'])
                    ? (string) $validated['reason']
                    : null
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'End',
            $ended,
            $oldValues,
            $this->periodAuditValues($ended),
            'Ended Teacher duty period.'
        );

        return response()->json([
            'message' => 'Teacher duty period ended successfully.',
            'data' => $this->periodResource($ended),
        ]);
    }

    public function currentAssignments(
        Request $request,
        string $period
    ): JsonResponse {
        $assignments = $this->teacherDutyOperation(
            fn () => $this->service->currentAssignmentsForPeriod(
                $this->schoolId($request),
                $this->teacherDutyResourceId($period)
            )
        );

        return response()->json([
            'data' => $assignments
                ->map(fn (TeacherDutyAssignment $assignment): array => $this->assignmentResource($assignment))
                ->values(),
        ]);
    }

    public function storeAssignment(
        StoreTeacherDutyAssignmentRequest $request,
        string $period
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $assignment = $this->teacherDutyOperation(
            fn () => $this->service->assignTeacher(
                $schoolId,
                $this->teacherDutyResourceId($period),
                (string) $validated['teacher_id'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $assignment,
            null,
            $this->assignmentAuditValues($assignment),
            'Assigned Teacher to duty period.'
        );

        return response()->json([
            'message' => 'Teacher assigned to duty period successfully.',
            'data' => $this->assignmentResource($assignment),
        ], 201);
    }

    public function assignmentHistory(
        Request $request,
        string $period
    ): JsonResponse {
        $assignments = $this->teacherDutyOperation(
            fn () => $this->service->assignmentHistoryForPeriod(
                $this->schoolId($request),
                $this->teacherDutyResourceId($period)
            )
        );

        return response()->json([
            'data' => $assignments
                ->map(fn (TeacherDutyAssignment $assignment): array => $this->assignmentResource($assignment))
                ->values(),
        ]);
    }

    public function showAssignment(
        Request $request,
        string $assignment
    ): JsonResponse {
        $assignmentModel = $this->teacherDutyOperation(
            fn () => $this->service->assignment(
                $this->schoolId($request),
                $this->teacherDutyResourceId($assignment)
            )
        );

        return response()->json([
            'data' => $this->assignmentResource($assignmentModel),
        ]);
    }

    public function endAssignment(
        EndTeacherDutyAssignmentRequest $request,
        string $assignment
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);
        $validated = $request->validated();

        $before = $this->teacherDutyOperation(
            fn () => $this->service->assignment(
                $schoolId,
                $this->teacherDutyResourceId($assignment)
            )
        );

        $oldValues = $this->assignmentAuditValues($before);

        $ended = $this->teacherDutyOperation(
            fn () => $this->service->endAssignment(
                $schoolId,
                $this->teacherDutyResourceId($assignment),
                $actorId,
                isset($validated['reason'])
                    ? (string) $validated['reason']
                    : null
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'End',
            $ended,
            $oldValues,
            $this->assignmentAuditValues($ended),
            'Ended Teacher duty assignment.'
        );

        return response()->json([
            'message' => 'Teacher duty assignment ended successfully.',
            'data' => $this->assignmentResource($ended),
        ]);
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

    private function periodResource(
        TeacherDutyPeriod $period
    ): array {
        return [
            'id' => $period->id,
            'academic_week_id' => $period->academic_week_id,
            'start_date' => $period->start_date?->format('Y-m-d'),
            'end_date' => $period->end_date?->format('Y-m-d'),
            'active' => (bool) $period->active,
            'end_reason' => $period->end_reason,
            'ended_at' => $period->ended_at,
            'created_at' => $period->created_at,
            'updated_at' => $period->updated_at,
        ];
    }

    private function assignmentResource(
        TeacherDutyAssignment $assignment
    ): array {
        return [
            'id' => $assignment->id,
            'duty_period_id' => $assignment->duty_period_id,
            'teacher_id' => $assignment->teacher_id,
            'active' => (bool) $assignment->active,
            'end_reason' => $assignment->end_reason,
            'ended_at' => $assignment->ended_at,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
        ];
    }

    private function periodAuditValues(
        TeacherDutyPeriod $period
    ): array {
        return [
            'academic_week_id' => $period->academic_week_id,
            'start_date' => $period->start_date?->format('Y-m-d'),
            'end_date' => $period->end_date?->format('Y-m-d'),
            'active' => (bool) $period->active,
            'end_reason' => $period->end_reason,
            'ended_at' => $period->ended_at,
        ];
    }

    private function assignmentAuditValues(
        TeacherDutyAssignment $assignment
    ): array {
        return [
            'duty_period_id' => $assignment->duty_period_id,
            'teacher_id' => $assignment->teacher_id,
            'active' => (bool) $assignment->active,
            'end_reason' => $assignment->end_reason,
            'ended_at' => $assignment->ended_at,
        ];
    }
}
