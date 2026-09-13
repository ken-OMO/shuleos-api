<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Validation\ValidationException;

class TeacherDutyAuthorizationService
{
    private const SUBMIT_PERMISSION = 'submit_teacher_duty_reports';

    private const REVIEW_PERMISSION = 'review_teacher_duty_reports';

    public function __construct(
        private readonly AuthContextService $authContext
    ) {}

    public function reporter(
        string $schoolId,
        string $periodId,
        string $actorUserId
    ): User {
        $actor = $this->eligibleSchoolUser(
            $schoolId,
            $actorUserId,
            'actor'
        );

        if (! $this->authContext->hasPermission(
            $actor,
            self::SUBMIT_PERMISSION
        )) {
            throw ValidationException::withMessages([
                'actor' => [
                    'The selected school user is not authorized to submit teacher duty reports.',
                ],
            ]);
        }

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->where('is_deleted', false)
            ->first();

        if (! $teacher) {
            throw ValidationException::withMessages([
                'actor' => [
                    'The selected school user does not have an eligible teacher profile.',
                ],
            ]);
        }

        $hasResponsibility = TeacherDutyAssignment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('duty_period_id', $periodId)
            ->where('teacher_id', $teacher->id)
            ->exists();

        if (! $hasResponsibility) {
            throw ValidationException::withMessages([
                'actor' => [
                    'The selected teacher is not responsible for this teacher duty period.',
                ],
            ]);
        }

        return $actor;
    }

    public function reviewer(
        string $schoolId,
        string $actorUserId
    ): User {
        $actor = $this->eligibleSchoolUser(
            $schoolId,
            $actorUserId,
            'reviewer'
        );

        if (! $this->authContext->hasPermission(
            $actor,
            self::REVIEW_PERMISSION
        )) {
            throw ValidationException::withMessages([
                'reviewer' => [
                    'The selected school user is not authorized to review teacher duty reports.',
                ],
            ]);
        }

        return $actor;
    }

    private function eligibleSchoolUser(
        string $schoolId,
        string $userId,
        string $field
    ): User {
        $user = User::query()
            ->withoutGlobalScopes()
            ->where('id', $userId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->whereNull('suspended_at')
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                $field => [
                    'The selected school user is not eligible for teacher duty authorization.',
                ],
            ]);
        }

        return $user;
    }
}
