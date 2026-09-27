<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\UpdateTeacherDutySettingsRequest;
use App\Services\TeacherDuty\TeacherDutySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TeacherDutySettingsController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Settings';

    public function __construct(
        private TeacherDutySettingsService $service
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->get(
                $this->schoolId($request)
            ),
        ]);
    }

    public function update(
        UpdateTeacherDutySettingsRequest $request
    ): JsonResponse {
        $result = $this->service->update(
            $this->schoolId($request),
            $request->safe()->only([
                'teacher_duty_report_deadline_time',
                'teacher_duty_report_grace_minutes',
            ])
        );

        $this->audit(
            $request,
            self::MODULE,
            'Update',
            $result['settings'],
            $result['old_values'],
            $result['new_values'],
            'Updated teacher duty settings.'
        );

        return response()->json([
            'message' => 'Teacher duty settings updated successfully.',
            'data' => $result['new_values'],
        ]);
    }

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        if (! $user) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        if (! $user->school_id) {
            throw new HttpException(403, 'School context is required.');
        }

        $schoolId = (string) $user->school_id;
        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId === null
            || (string) $tenantSchoolId !== $schoolId
        ) {
            throw new HttpException(
                403,
                'School context does not match authenticated user.'
            );
        }

        return $schoolId;
    }
}
