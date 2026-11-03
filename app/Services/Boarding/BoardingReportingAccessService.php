<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\School;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\School\SchoolOperatingModePolicy;
use App\Services\SchoolSetupReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class BoardingReportingAccessService
{
    public function __construct(
        private AuthContextService $auth,
        private SchoolOperatingModePolicy $mode,
        private SchoolSetupReadinessService $readiness,
    ) {}

    public function actor(User $authenticated, bool $write = false): User
    {
        if ($write && DB::transactionLevel() < 1) {
            throw new LogicException('Reporting writes require a transaction.');
        }

        $identity = User::withoutGlobalScopes()->whereKey($authenticated->id)->first();
        abort_unless(
            $identity && $identity->school_id
            && (string) $identity->school_id === (string) $authenticated->school_id,
            403
        );

        $schoolQuery = School::withoutGlobalScopes()->whereKey($identity->school_id);
        if ($write) {
            $schoolQuery->lockForUpdate();
        }
        $school = $schoolQuery->first();
        abort_unless($school !== null, 403);

        $actorQuery = User::withoutGlobalScopes()
            ->whereKey($identity->id)->where('school_id', $school->id);
        if ($write) {
            $actorQuery->sharedLock();
        }
        $actor = $actorQuery->first();
        abort_unless($actor !== null, 403);

        $actor->setRelation('school', $school);
        $this->auth->assertAccessible($actor);

        if ($write) {
            abort_unless($school->lifecycle_state === 'active', 403);
            $this->mode->assertBoardingAllowed((string) $school->id);
            abort_unless($this->readiness->isReady((string) $school->id), 403);
        }

        return $actor;
    }

    public function requirePermission(User $actor, string $permission): void
    {
        abort_unless($this->auth->hasPermission($actor, $permission), 403);
    }

    public function schoolWideReader(User $actor): bool
    {
        return $this->auth->hasPermission($actor, 'view_boarding_operations')
            || $this->auth->hasPermission($actor, 'review_boarding_reports');
    }

    public function assignment(User $actor, object $obligation): object
    {
        abort_unless((string) $obligation->school_id === (string) $actor->school_id, 403);

        $assignment = DB::table('hostel_staff_assignments')
            ->where('school_id', $actor->school_id)
            ->where('hostel_id', $obligation->hostel_id)
            ->where('user_id', $actor->id)
            ->where('effective_from', '<=', $obligation->reporting_date)
            ->where(fn ($query) => $query->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $obligation->reporting_date))
            ->orderByDesc('effective_from')->orderBy('id')->first();

        abort_unless($assignment !== null, 403);

        return $assignment;
    }

    public function assertReportDate(User $actor, object $obligation): void
    {
        $timezone = $actor->school->timezone ?: config('app.timezone');
        abort_if(
            $obligation->reporting_date > CarbonImmutable::now($timezone)->toDateString(),
            422,
            'Future reporting dates are unavailable.'
        );
    }
}
