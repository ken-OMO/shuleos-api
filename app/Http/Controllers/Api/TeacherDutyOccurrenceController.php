<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\StoreTeacherDutyOccurrenceRequest;
use App\Models\TeacherDutyOccurrence;
use App\Models\TeacherDutyPeriod;
use App\Services\TeacherDuty\TeacherDutyAuthorizationService;
use App\Services\TeacherDuty\TeacherDutyOccurrenceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeacherDutyOccurrenceController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Occurrences';

    public function __construct(
        private readonly TeacherDutyOccurrenceService $service,
        private readonly TeacherDutyAuthorizationService $authorization
    ) {}

    public function index(
        Request $request,
        string $period
    ): JsonResponse {
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

        $occurrences = $this->teacherDutyOperation(
            fn () => $this->service->occurrencesForPeriod(
                $schoolId,
                $periodId
            )
        );

        return response()->json([
            'data' => $occurrences
                ->map(
                    fn (TeacherDutyOccurrence $occurrence): array => $this->occurrenceResource($occurrence)
                )
                ->values(),
        ]);
    }

    public function store(
        StoreTeacherDutyOccurrenceRequest $request,
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

        $occurrence = $this->teacherDutyOperation(
            fn () => $this->service->recordOccurrence(
                $schoolId,
                $periodId,
                (string) $validated['occurrence_category_id'],
                (string) $validated['occurrence_date'],
                isset($validated['occurrence_time'])
                    ? (string) $validated['occurrence_time']
                    : null,
                (string) $validated['description'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $occurrence,
            null,
            $this->occurrenceAuditValues($occurrence),
            'Recorded Teacher duty occurrence.'
        );

        return response()->json([
            'message' => 'Teacher duty occurrence recorded successfully.',
            'data' => $this->occurrenceResource($occurrence),
        ], 201);
    }

    public function show(
        Request $request,
        string $occurrence
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $occurrenceModel = $this->teacherDutyOperation(
            fn () => $this->service->occurrence(
                $schoolId,
                $this->teacherDutyResourceId($occurrence)
            )
        );

        $this->authorization->reporter(
            $schoolId,
            (string) $occurrenceModel->duty_period_id,
            $actorId
        );

        return response()->json([
            'data' => $this->occurrenceResource($occurrenceModel),
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

    private function occurrenceResource(
        TeacherDutyOccurrence $occurrence
    ): array {
        return [
            'id' => $occurrence->id,
            'duty_period_id' => $occurrence->duty_period_id,
            'occurrence_category_id' => $occurrence->occurrence_category_id,
            'occurrence_date' => $occurrence->occurrence_date?->format('Y-m-d'),
            'occurrence_time' => $occurrence->occurrence_time,
            'description' => $occurrence->description,
            'recorded_by' => $occurrence->recorded_by,
            'created_at' => $occurrence->created_at,
            'updated_at' => $occurrence->updated_at,
        ];
    }

    private function occurrenceAuditValues(
        TeacherDutyOccurrence $occurrence
    ): array {
        return [
            'duty_period_id' => $occurrence->duty_period_id,
            'occurrence_category_id' => $occurrence->occurrence_category_id,
            'occurrence_date' => $occurrence->occurrence_date?->format('Y-m-d'),
            'occurrence_time' => $occurrence->occurrence_time,
            'description' => $occurrence->description,
        ];
    }
}
