<?php

namespace App\Services\Administrator;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolLifecycleAdministrationService
{
    private const TRANSITIONS = [
        'onboarding' => ['trial', 'active', 'archived'], 'trial' => ['active', 'grace', 'suspended', 'archived'],
        'active' => ['grace', 'read_only', 'suspended', 'locked', 'archived'], 'grace' => ['active', 'read_only', 'suspended', 'locked', 'archived'],
        'read_only' => ['active', 'suspended', 'locked', 'archived'], 'suspended' => ['active', 'grace', 'locked', 'archived'],
        'locked' => ['active', 'suspended', 'archived'], 'archived' => [],
    ];

    public function __construct(private AdministratorPortalAccessService $access, private AdministratorAuditService $audit) {}

    public function transition(User $actor, string $schoolId, string $to, ?string $reason): School
    {
        $this->access->requirePlatform($actor, 'manage_school_lifecycle');
        if (in_array($to, ['suspended', 'locked', 'archived'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for this lifecycle transition.']);
        }

        return DB::transaction(function () use ($actor, $schoolId, $to, $reason) {
            $school = School::whereKey($schoolId)->where('is_deleted', false)->lockForUpdate()->firstOrFail();
            $from = $school->lifecycle_state ?: ($school->active ? 'active' : 'suspended');
            if ($from === $to) {
                return $school;
            }
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['state' => "Invalid school lifecycle transition from {$from} to {$to}."]);
            }
            $values = ['lifecycle_state' => $to, 'lifecycle_version' => $school->lifecycle_version + 1, 'active' => ! in_array($to, ['suspended', 'locked', 'archived'], true), 'updated_at' => now()];
            if ($to === 'suspended') {
                $values['suspended_at'] = now();
            }
            if ($to === 'locked') {
                $values['locked_at'] = now();
            }
            if ($to === 'archived') {
                $values['archived_at'] = now();
            }
            if (in_array($to, ['active', 'grace'], true)) {
                $values += ['suspended_at' => null, 'locked_at' => null];
            }
            $school->update($values);
            DB::table('school_lifecycle_history')->insert(['id' => (string) Str::uuid(), 'school_id' => $school->id, 'from_state' => $from, 'to_state' => $to, 'reason' => $reason ? strip_tags($reason) : null, 'actor_user_id' => $actor->id, 'created_at' => now()]);
            $this->audit->record($actor, 'school_lifecycle_'.$to, 'schools', $school->id, ['state' => $from], ['state' => $to, 'reason' => $reason], $school->id);

            return $school->fresh();
        });
    }
}
