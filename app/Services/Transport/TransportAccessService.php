<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\School;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\SchoolSetupReadinessService;
use Illuminate\Support\Facades\DB;
use LogicException;

class TransportAccessService
{
    public function __construct(
        private AuthContextService $auth,
        private SchoolSetupReadinessService $readiness,
    ) {}

    public function actor(User $authenticated, bool $write = false): User
    {
        if ($write && DB::transactionLevel() < 1) {
            throw new LogicException('Transport writes require a transaction.');
        }

        $identity = User::withoutGlobalScopes()
            ->whereKey($authenticated->getKey())->first();

        abort_unless(
            $identity !== null
            && $identity->getAttribute('school_id') !== null
            && (string) $identity->school_id === (string) $authenticated->school_id,
            403
        );

        // Common write lock order: school, actor, then domain resources.
        $schoolQuery = School::withoutGlobalScopes()
            ->whereKey($identity->school_id);

        if ($write) {
            $schoolQuery->lockForUpdate();
        }

        $school = $schoolQuery->first();
        abort_unless($school !== null, 403);

        $actorQuery = User::withoutGlobalScopes()
            ->whereKey($identity->getKey())
            ->where('school_id', $school->getKey());

        if ($write) {
            $actorQuery->sharedLock();
        }

        $actor = $actorQuery->first();
        abort_unless($actor !== null, 403);

        $actor->setRelation('school', $school);
        $this->auth->assertAccessible($actor);

        if ($write) {
            abort_unless($school->lifecycle_state === 'active', 403);
            abort_unless(
                in_array($school->operating_mode, ['day', 'day_and_boarding'], true),
                403,
                'Regular transport requires a day or mixed school.'
            );
            abort_unless(
                $this->readiness->isReady((string) $school->getKey()),
                403,
                'School setup is incomplete.'
            );
        }

        return $actor;
    }

    public function requirePermission(User $actor, string $permission): void
    {
        abort_unless($this->auth->hasPermission($actor, $permission), 403);
    }

    public function requireOwnership(User $actor, object $resource): void
    {
        abort_unless(
            isset($resource->school_id)
            && (string) $resource->school_id === (string) $actor->school_id,
            404
        );
    }
}
