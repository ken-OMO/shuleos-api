<?php

namespace App\Services\TeacherPortal;

use App\Models\HomeworkAssignment;
use App\Models\LearningResource;
use App\Models\LessonNote;
use App\Models\LessonPlan;
use App\Models\RecordOfWork;
use App\Models\SchemeOfWork;
use App\Models\TeacherWorkflow;
use App\Models\TeacherWorkflowHistory;
use App\Models\User;
use App\Services\Teaching\CurriculumCoverageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherWorkflowService
{
    private const TYPES = ['scheme_of_work', 'lesson_plan', 'lesson_note', 'record_of_work', 'homework', 'learning_resource'];

    public function __construct(private TeacherPortalAccessService $access, private TeacherHodScopeService $hod, private CurriculumCoverageService $coverage) {}

    public function submit(User $user, string $type, string $entityId): TeacherWorkflow
    {
        return $this->transitionOwner($user, $type, $entityId, 'submitted');
    }

    public function withdraw(User $user, string $type, string $entityId): TeacherWorkflow
    {
        return $this->transitionOwner($user, $type, $entityId, 'draft');
    }

    public function history(User $user, string $type, string $entityId)
    {
        $workflow = $this->ownedWorkflow($user, $type, $entityId);

        return $workflow->history()->oldest('created_at')->get();
    }

    public function review(User $reviewer, string $workflowId, string $action, ?string $reason): TeacherWorkflow
    {
        $scope = $this->hod->scope($reviewer);
        abort_unless(in_array($action, ['approved', 'changes_requested', 'rejected'], true), 422);
        if (in_array($action, ['changes_requested', 'rejected'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A review reason is required.']);
        }

        return DB::transaction(function () use ($reviewer, $workflowId, $action, $reason, $scope) {
            $workflow = TeacherWorkflow::withoutGlobalScopes()->where('school_id', $reviewer->school_id)->whereKey($workflowId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($workflow->state, ['submitted', 'under_review'], true), 409);
            abort_if($workflow->submitted_by === $reviewer->id, 403, 'Submitters cannot approve their own work.');
            $this->hod->assertWorkflow($scope, $workflow);
            $from = $workflow->state;
            $workflow->update(['state' => $action, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'version' => $workflow->version + 1, 'approved_snapshot' => $action === 'approved' ? $this->entity($workflow->entity_type, $workflow->entity_id)->toArray() : $workflow->approved_snapshot]);
            if ($action === 'approved' && $workflow->entity_type === 'lesson_plan') {
                LessonPlan::withoutGlobalScopes()->whereKey($workflow->entity_id)->update(['status' => 'approved']);
            }
            if ($action === 'approved' && $workflow->entity_type === 'record_of_work' && ! DB::table('curriculum_coverage')->where('record_of_work_id', $workflow->entity_id)->exists()) {
                $this->coverage->create($workflow->entity_id, $workflow->school_id);
            }
            $this->append($workflow, $reviewer, $from, $action, $reason);

            return $workflow->fresh();
        });
    }

    public function queue(User $user)
    {
        $scope = $this->hod->scope($user);
        $query = TeacherWorkflow::withoutGlobalScopes()->where('school_id', $user->school_id)->whereIn('state', ['submitted', 'under_review']);
        if (! $scope['whole_school']) {
            $query->whereIn('teacher_assignment_id', DB::table('teacher_assignments')->where('school_id', $user->school_id)->whereIn('learning_area_id', $scope['learning_area_ids'])->pluck('id'));
        }

        return $query->latest()->paginate(30);
    }

    public function tasks(User $user)
    {
        $teacher = $this->access->teacher($user);
        $tasks = TeacherWorkflow::withoutGlobalScopes()->where('school_id', $user->school_id)->where('teacher_id', $teacher->id)->whereIn('state', ['draft', 'changes_requested', 'rejected'])->orderByDesc('updated_at')->limit(35)->get()->map(fn ($item) => ['task_type' => $item->entity_type.'_'.($item->state === 'changes_requested' ? 'changes_requested' : 'due'), 'title' => Str::headline($item->entity_type), 'priority' => $item->state === 'changes_requested' ? 'high' : 'normal', 'entity_reference' => $item->entity_id, 'status' => $item->state, 'deep_link' => '/teacher/'.Str::plural(str_replace('_', '-', $item->entity_type)).'/'.$item->entity_id]);
        if (Schema::hasTable('attendance_registers')) {
            DB::table('attendance_registers')->where('school_id', $user->school_id)->where('teacher_id', $teacher->id)->where('status', 'draft')->limit(10)->get()->each(fn ($item) => $tasks->push(['task_type' => 'attendance_pending', 'title' => 'Attendance register pending', 'priority' => 'high', 'entity_reference' => $item->id, 'status' => 'draft', 'deep_link' => '/teacher/attendance/registers/'.$item->id]));
        }
        if (Schema::hasTable('mark_entry_batches')) {
            DB::table('mark_entry_batches')->where('school_id', $user->school_id)->where('teacher_id', $teacher->id)->whereIn('status', ['draft', 'changes_requested'])->limit(10)->get()->each(fn ($item) => $tasks->push(['task_type' => $item->status === 'changes_requested' ? 'marks_correction_requested' : 'marks_entry_pending', 'title' => 'Mark entry batch', 'priority' => $item->status === 'changes_requested' ? 'high' : 'normal', 'entity_reference' => $item->id, 'status' => $item->status, 'deep_link' => '/teacher/marks-entry/batches/'.$item->id]));
        }

        return $tasks->unique(fn ($item) => $item['task_type'].'|'.$item['entity_reference'])->take(50)->values();
    }

    private function transitionOwner(User $user, string $type, string $entityId, string $to): TeacherWorkflow
    {
        return DB::transaction(function () use ($user, $type, $entityId, $to) {
            [$entity, $assignment] = $this->ownedEntity($user, $type, $entityId);
            $teacher = $this->access->teacher($user);
            $workflow = TeacherWorkflow::withoutGlobalScopes()->where('school_id', $user->school_id)->where('entity_type', $type)->where('entity_id', $entityId)->lockForUpdate()->first();
            if (! $workflow) {
                $workflow = TeacherWorkflow::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'entity_type' => $type, 'entity_id' => $entityId, 'teacher_id' => $teacher->id, 'teacher_assignment_id' => $assignment?->id, 'state' => 'draft']);
            }
            $allowed = ['draft' => ['submitted'], 'changes_requested' => ['submitted', 'draft'], 'rejected' => ['draft'], 'submitted' => ['draft'], 'under_review' => [], 'approved' => [], 'archived' => []];
            abort_unless(in_array($to, $allowed[$workflow->state] ?? [], true), 409, 'Invalid workflow transition.');
            if ($to === 'submitted' && $type === 'scheme_of_work') {
                abort_unless($entity->lessons()->exists(), 422, 'A scheme needs at least one lesson before submission.');
            }
            $from = $workflow->state;
            $values = ['state' => $to, 'version' => $workflow->version + 1, 'review_reason' => null];
            if ($to === 'submitted') {
                $values += ['submitted_by' => $user->id, 'submitted_at' => $workflow->submitted_at ?? now()];
                if ($type === 'lesson_plan') {
                    $entity->update(['status' => 'submitted']);
                }
            } else {
                $values['revision_number'] = $workflow->revision_number + 1;
                if ($type === 'lesson_plan') {
                    $entity->update(['status' => 'draft']);
                }
            }
            $workflow->update($values);
            $this->append($workflow, $user, $from, $to);

            return $workflow->fresh();
        });
    }

    private function ownedWorkflow(User $user, string $type, string $entityId): TeacherWorkflow
    {
        $this->ownedEntity($user, $type, $entityId);

        return TeacherWorkflow::withoutGlobalScopes()->where('school_id', $user->school_id)->where('entity_type', $type)->where('entity_id', $entityId)->firstOrFail();
    }

    private function ownedEntity(User $user, string $type, string $id): array
    {
        $entity = $this->entity($type, $id);
        abort_unless($entity->school_id === $user->school_id, 404);
        $assignmentId = match ($type) {
            'lesson_plan' => $entity->teacher_assignment_id,
            'lesson_note', 'record_of_work' => $entity->lessonPlan?->teacher_assignment_id,
            'homework' => $entity->teacher_assignment_id,
            default => null,
        };
        if ($type === 'learning_resource') {
            abort_unless($entity->uploaded_by === $user->id, 403);
        }
        if ($type === 'scheme_of_work') {
            $assignment = $this->access->assignments($user)->first(fn ($item) => $item->learning_area_id === $entity->learning_area_id && $item->grade_id === $entity->grade_id && $item->academic_year_id === $entity->academic_year_id && $item->term_id === $entity->term_id);
            abort_unless($assignment, 403);
        } else {
            $assignment = $assignmentId ? $this->access->requireAssignment($user, $assignmentId) : null;
        }

        return [$entity, $assignment];
    }

    private function entity(string $type, string $id): Model
    {
        abort_unless(in_array($type, self::TYPES, true), 422);
        $model = match ($type) {
            'scheme_of_work' => SchemeOfWork::class,
            'lesson_plan' => LessonPlan::class,
            'lesson_note' => LessonNote::class,
            'record_of_work' => RecordOfWork::class,
            'homework' => HomeworkAssignment::class,
            'learning_resource' => LearningResource::class,
        };

        return $model::withoutGlobalScopes()->whereKey($id)->when(method_exists($model, 'scopeCurrent'), fn ($query) => $query->current())->with($type === 'lesson_note' || $type === 'record_of_work' ? 'lessonPlan' : [])->firstOrFail();
    }

    private function append(TeacherWorkflow $workflow, User $actor, string $from, string $to, ?string $reason = null): void
    {
        TeacherWorkflowHistory::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'school_id' => $workflow->school_id, 'workflow_id' => $workflow->id, 'actor_user_id' => $actor->id, 'from_state' => $from, 'to_state' => $to, 'reason' => $reason, 'version' => $workflow->version, 'created_at' => now()]);
    }
}
