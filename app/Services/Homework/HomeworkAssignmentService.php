<?php

namespace App\Services\Homework;

use App\Models\HomeworkAssignment;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeworkAssignmentService
{
    public function teacher(User $user)
    {
        return DB::table('teachers')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('active', true)->where('is_deleted', false)->first() ?: throw new AuthorizationException('Active teacher profile required.');
    }

    public function ownQuery(User $user)
    {
        $teacher = $this->teacher($user);

        return HomeworkAssignment::current()->where('school_id', $user->school_id)->where('teacher_id', $teacher->id);
    }

    public function create(User $user, array $data): HomeworkAssignment
    {
        $teacher = $this->teacher($user);
        $ta = TeacherAssignment::current()->whereKey($data['teacher_assignment_id'])->where('school_id', $user->school_id)->where('teacher_id', $teacher->id)->where('active', true)->firstOrFail();
        $publish = $data['publish_at'] ?? now();
        if (now()->parse($data['due_at'])->lte(now()->parse($publish))) {
            throw ValidationException::withMessages(['due_at' => 'Due date must follow publication time.']);
        }
        foreach (['scheme_lesson_id' => 'scheme_lessons', 'lesson_plan_id' => 'lesson_plans'] as $field => $table) {
            if (! empty($data[$field]) && ! DB::table($table)->where('id', $data[$field])->where('school_id', $user->school_id)->exists()) {
                throw ValidationException::withMessages([$field => 'Reference is outside this school.']);
            }
        }
        if (! empty($data['lesson_plan_id']) && ! DB::table('lesson_plans')->where('id', $data['lesson_plan_id'])->where('teacher_assignment_id', $ta->id)->exists()) {
            throw ValidationException::withMessages(['lesson_plan_id' => 'Lesson plan is incompatible with the teacher assignment.']);
        }

        return DB::transaction(function () use ($user, $teacher, $ta, $data) {
            $a = HomeworkAssignment::create($data + ['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'teacher_id' => $teacher->id, 'learning_area_id' => $ta->learning_area_id, 'grade_id' => $ta->grade_id, 'stream_id' => $ta->stream_id, 'academic_year_id' => $ta->academic_year_id, 'term_id' => $ta->term_id, 'status' => 'draft', 'created_by' => $user->id]);
            $this->audit($a, 'assignment_created', $user->id);

            return $a;
        });
    }

    public function update(User $user, string $id, array $data): HomeworkAssignment
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $a = $this->ownQuery($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($a->status, ['draft', 'scheduled'], true), 409);
            if (isset($data['due_at'])) {
                $publish = $data['publish_at'] ?? $a->publish_at ?? now();
                if (now()->parse($data['due_at'])->lte(now()->parse($publish))) {
                    throw ValidationException::withMessages(['due_at' => 'Due date must follow publication time.']);
                }
            } $a->update($data + ['updated_by' => $user->id]);
            $this->audit($a, 'assignment_updated', $user->id, ['fields' => array_keys($data)]);

            return $a;
        });
    }

    public function attachResource(User $user, string $id, string $resource, bool $required = false, int $order = 0): void
    {
        $a = $this->ownQuery($user)->whereKey($id)->whereIn('status', ['draft', 'scheduled'])->firstOrFail();
        $r = DB::table('learning_resources')->where('id', $resource)->where('school_id', $user->school_id)->where('publication_status', 'published')->where('is_deleted', false)->where('learning_area_id', $a->learning_area_id)->where('grade_id', $a->grade_id)->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $a->stream_id))->first();
        if (! $r) {
            throw ValidationException::withMessages(['learning_resource_id' => 'Published applicable resource required.']);
        } DB::table('homework_assignment_resources')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'assignment_id' => $a->id, 'learning_resource_id' => $resource, 'required' => $required, 'display_order' => $order, 'created_at' => now()]);
    }

    public function transition(User $user, string $id, string $to): HomeworkAssignment
    {
        return DB::transaction(function () use ($user, $id, $to) {
            $a = $this->ownQuery($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['scheduled', 'published', 'cancelled'], 'scheduled' => ['published', 'cancelled'], 'published' => ['closed', 'cancelled'], 'closed' => ['marking', 'archived'], 'marking' => ['marked', 'archived'], 'marked' => ['archived']];
            if (! in_array($to, $allowed[$a->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid homework lifecycle transition.']);
            } if ($to === 'published') {
                if (now()->gte($a->due_at)) {
                    throw ValidationException::withMessages(['due_at' => 'Cannot publish after the due date.']);
                } $this->generateLearners($a);
                $a->published_at = now();
            } $a->status = $to;
            $a->updated_by = $user->id;
            $a->save();
            $this->audit($a, $to, $user->id);

            return $a;
        });
    }

    public function generateLearners(HomeworkAssignment $a): int
    {
        $learners = DB::table('learners')->where('school_id', $a->school_id)->where('grade_id', $a->grade_id)->when($a->stream_id, fn ($q, $v) => $q->where('stream_id', $v))->where('active', true)->where('is_deleted', false)->get();
        $n = 0;
        foreach ($learners as $l) {
            $n += DB::table('homework_assignment_learners')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $a->school_id, 'assignment_id' => $a->id, 'learner_id' => $l->id, 'assigned_at' => now(), 'availability_status' => 'available', 'submission_status' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }

    public function audit(HomeworkAssignment $a, string $action, ?string $actor, array $metadata = []): void
    {
        DB::table('homework_assignment_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $a->school_id, 'assignment_id' => $a->id, 'actor_user_id' => $actor, 'action' => $action, 'metadata' => $metadata ? json_encode($metadata) : null, 'created_at' => now()]);
    }
}
