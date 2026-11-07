<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\School;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\SchoolSetupReadinessService;
use App\Services\TransportSubscription\TransportEntitlementService;
use Illuminate\Support\Facades\DB;
use LogicException;

class TransportAccessService
{
    public function __construct(
        private AuthContextService $auth,
        private SchoolSetupReadinessService $readiness,
        private TransportEntitlementService $entitlements,
    ) {}

    public function actor(User $authenticated, bool $write = false): User
    {
        $actor = $this->identity($authenticated, $write);
        if ($write) {
            $this->normalSetup($actor);
            $this->bind($actor, $this->entitlements->requireNormal($actor));
        }

        return $actor;
    }

    public function journeyActor(User $user, string $roster, string $boundary): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->journey($actor, $roster, $boundary));
    }

    public function attendanceActor(User $user, string $roster): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->attendance($actor, $roster));
    }

    public function completionActor(User $user, string $trip): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->completion($actor, $trip));
    }

    public function exceptionActor(User $user, string $failure): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->exception($actor, $failure));
    }

    public function authorizationActor(User $user, array $data): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->authorization($actor, $data));
    }

    public function revocationActor(User $user, string $id): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->revocation($actor, $id));
    }

    public function reportActor(User $user, string $id, bool $submission = false): User
    {
        return $this->scopedActor($user, fn (User $actor): array => $this->entitlements->report($actor, $id, $submission));
    }

    private function scopedActor(User $authenticated, callable $continuation): User
    {
        $actor = $this->identity($authenticated, true);
        $normal = $this->entitlements->normal($actor);
        if ($normal !== null) {
            $this->normalSetup($actor);

            return $this->bind($actor, $normal);
        }
        // Lifecycle security locks were already checked by fresh AuthContext.
        // Read-only/grace may close concrete existing work, never start new operations.
        abort_unless(in_array($actor->school->lifecycle_state, ['active', 'grace', 'read_only'], true), 403);
        $this->normalSetup($actor, false);

        return $this->bind($actor, $continuation($actor));
    }

    private function bind(User $actor, array $basis): User
    {
        $actor->setRelation('transportAccessBasis', (object) $basis);

        return $actor;
    }

    private function normalSetup(User $actor, bool $normal = true): void
    {
        $school = $actor->school;
        abort_unless(! $normal || $school->lifecycle_state === 'active', 403);
        abort_unless(in_array($school->operating_mode, ['day', 'day_and_boarding'], true), 403,
            'Regular transport requires a day or mixed school.');
        abort_unless($this->readiness->isReady((string) $school->getKey()), 403, 'School setup is incomplete.');
    }

    private function identity(User $authenticated, bool $write = false): User
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
