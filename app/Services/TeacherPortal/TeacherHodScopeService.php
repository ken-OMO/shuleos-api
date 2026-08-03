<?php

namespace App\Services\TeacherPortal;

use App\Models\TeacherWorkflow;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TeacherHodScopeService
{
    public function __construct(private LeadershipPortalAccessService $leadership) {}

    public function scope(User $user): array
    {
        $scope = $this->leadership->scope($user);
        if ($scope['role'] === 'HOD' && empty($scope['learning_area_ids'])) {
            throw new AuthorizationException('Active HOD scope required.');
        }

        return $scope;
    }

    public function assertWorkflow(array $scope, TeacherWorkflow $workflow): void
    {
        if ($scope['whole_school']) {
            return;
        }
        $allowed = DB::table('teacher_assignments')->where('school_id', $scope['school_id'])->whereKey($workflow->teacher_assignment_id)->whereIn('learning_area_id', $scope['learning_area_ids'])->where('active', true)->where('is_deleted', false)->exists();
        if (! $allowed) {
            throw new AuthorizationException('Workflow is outside departmental scope.');
        }
    }
}
