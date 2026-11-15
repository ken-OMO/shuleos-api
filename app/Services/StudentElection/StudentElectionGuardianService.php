<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit candidacy authority, never inferred from contact or pickup flags. */
class StudentElectionGuardianService
{
    private const TABLE = 'student_election_guardian_designations';

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function user(User $actor, bool $manager): User
    {
        $actor = User::withoutGlobalScopes()->where('school_id', $actor->school_id)->whereKey($actor->id)->sharedLock()->firstOrFail();
        $auth = app(AuthContextService::class);
        $auth->assertAccessible($actor);
        abort_unless($actor->getAttribute('school_id') !== null, 403);
        if ($manager) {
            abort_unless($auth->hasPermission($actor, 'manage_student_elections'), 403);
        }
        $school = DB::table('schools')->where('id', $actor->school_id)->sharedLock()->firstOrFail();
        abort_unless($school->active && ! $school->is_deleted, 403);
        abort_if(in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true) || ($manager && $school->lifecycle_state === 'read_only'), 403);

        return $actor;
    }

    private function election(string $school, string $id, bool $exclusive): StudentElection
    {
        $q = StudentElection::current()->where('school_id', $school)->whereKey($id);

        return ($exclusive ? $q->lockForUpdate() : $q->sharedLock())->firstOrFail();
    }

    private function learner(StudentElection $e, string $id): void
    {
        // Every designation and consent path takes this lock before reading its head.
        DB::table('learners')->where('id', $id)->where('school_id', $e->school_id)
            ->where('active', true)->where('is_deleted', false)->lockForUpdate()->firstOrFail();
    }

    private function latest(StudentElection $e, string $learner): ?object
    {
        return DB::table(self::TABLE)->where('school_id', $e->school_id)->where('election_id', $e->id)
            ->where('learner_id', $learner)->orderByDesc('revision')->first();
    }

    private function linked(StudentElection $e, string $learner, string $parent, ?string $linkId = null): array
    {
        $p = DB::table('parents')->where('id', $parent)->where('school_id', $e->school_id)
            ->where('active', true)->where('is_deleted', false)->sharedLock()->firstOrFail();
        if (! $p->user_id) {
            $this->fail('guardian', 'A guardian with their own authenticated account is required.');
        }
        $account = User::withoutGlobalScopes()->where('school_id', $e->school_id)->whereKey($p->user_id)->sharedLock()->firstOrFail();
        app(AuthContextService::class)->assertAccessible($account);
        $role = app(AuthContextService::class);
        abort_unless($role->hasRole($account, 'Parent'), 403);
        $links = DB::table('learner_parents')->where('parent_id', $p->id)->where('learner_id', $learner)
            ->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
        if ($linkId !== null) {
            $links->where('id', $linkId);
        }
        $links = $links->orderBy('id')->sharedLock()->get();
        if ($links->count() !== 1) {
            $this->fail('guardian', 'One unambiguous active guardian link is required.');
        }

        return [$p, $account, $links->first()];
    }

    private function summary(object $event): array
    {
        return ['version' => $event->id, 'revision' => (int) $event->revision, 'action' => $event->action,
            'learner_id' => $event->learner_id, 'parent_id' => $event->parent_id];
    }

    public function designate(User $actor, string $election, string $learner, string $parent, ?string $expectedVersion, string $reason): array
    {
        return $this->change($actor, $election, $learner, $parent, $expectedVersion, $reason);
    }

    public function revoke(User $actor, string $election, string $learner, string $expectedVersion, string $reason): array
    {
        return $this->change($actor, $election, $learner, null, $expectedVersion, $reason);
    }

    private function change(User $actor, string $election, string $learner, ?string $parent, ?string $expected, string $reason): array
    {
        return DB::transaction(function () use ($actor, $election, $learner, $parent, $expected, $reason) {
            // Election first: future open/consent/review integration must preserve this order.
            $e = $this->election((string) $actor->school_id, $election, true);
            $actor = $this->user($actor, true);
            app(StudentElectionCommercialAccess::class)->normal($e->school_id);
            if (! in_array($e->status, ['draft', 'nominations_open', 'nominations_closed', 'campaigning'], true)
                || DB::table('student_election_electorates')->where('election_id', $e->id)->exists()) {
                $this->fail('election', 'Guardian designation changes are unavailable after voting opens. Contact the election officer for incident review.');
            }
            $this->learner($e, $learner);
            $head = $this->latest($e, $learner);
            abort_unless(($head?->id) === $expected, 409, 'Guardian designation changed. Refresh before retrying.');
            $reason = trim($reason);
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
                $this->fail('reason', 'A verification or revocation reason of 10 to 1000 characters is required.');
            }
            if ($parent === null && (! $head || $head->action !== 'designate')) {
                $this->fail('guardian', 'No current designation can be revoked.');
            }
            [$p, $account, $link] = $parent === null ? [null, null, null] : $this->linked($e, $learner, $parent);
            $event = ['id' => (string) Str::uuid(), 'school_id' => $e->school_id, 'election_id' => $e->id,
                'learner_id' => $learner, 'revision' => ($head->revision ?? 0) + 1,
                'action' => $parent === null ? 'revoke' : 'designate', 'parent_id' => $p?->id,
                'parent_user_id' => $account?->id, 'learner_parent_id' => $link?->id,
                'actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => now()];
            if (! DB::table(self::TABLE)->insert($event)
                || ! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $e->school_id,
                    'election_id' => $e->id, 'actor_user_id' => $actor->id, 'action' => 'guardian_'.$event['action'],
                    'entity_type' => 'guardian_designation', 'entity_id' => $event['id'],
                    'metadata' => json_encode(['revision' => $event['revision']], JSON_THROW_ON_ERROR), 'created_at' => now()])) {
                throw new \RuntimeException('Guardian evidence and audit must commit atomically.');
            }

            if ($e->application_method === 'versioned_v1') {
                app(StudentElectionApplicationService::class)->invalidateGuardian($e, $learner);
            }

            app(StudentElectionCommercialAccess::class)->normal($e->school_id);

            return $this->summary((object) $event);
        });
    }

    /** Internal hook: the caller must retain these locks through consent persistence. */
    public function guardianForConsent(User $actor, string $election, string $learner, string $expectedVersion): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Guardian checks must share the consent transaction.');
        }
        $e = $this->election((string) $actor->school_id, $election, false);
        $this->learner($e, $learner);
        $actor = $this->user($actor, false);
        $head = $this->latest($e, $learner);
        abort_unless($head && $head->action === 'designate' && $head->id === $expectedVersion, 409, 'Current guardian designation required.');
        [$p, $account, $link] = $this->linked($e, $learner, $head->parent_id, $head->learner_parent_id);
        abort_unless($actor->id === $head->parent_user_id && $account->id === $head->parent_user_id, 403);
        $portal = app(StudentElectionGuardianAccess::class);
        $guardian = $portal->parent($actor);
        abort_unless($guardian->id === $p->id && $portal->requireLink($actor, $learner)->id === $link->id, 403);

        return $this->summary($head);
    }
}
