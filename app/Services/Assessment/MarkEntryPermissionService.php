<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\MarkEntryPermission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarkEntryPermissionService
{
    public function create(array $data, string $school): MarkEntryPermission
    {
        return DB::transaction(function () use ($data, $school) {
            app(ExamResultLifecycleService::class)->assertSchoolWritable($school);

            $values = Validator::make($data, [
                'exam_id' => 'required|uuid',
                'role_name' => ['required', 'string', 'max:255', 'regex:/\S/'],
                'active' => 'sometimes|boolean',
                'opens_at' => 'sometimes|nullable|date',
                'closes_at' => 'sometimes|nullable|date',
            ])->validate();

            $exam = app(ExamResultLifecycleService::class)
                ->lockExamForWrite($school, $values['exam_id']);
            $this->assertCanOpen($exam);
            $this->assertWindow($values['opens_at'] ?? null, $values['closes_at'] ?? null);

            $role = mb_strtolower(trim($values['role_name']));
            if (MarkEntryPermission::current()->where('exam_id', $exam->id)
                ->whereRaw('LOWER(role_name) = ?', [$role])->exists()) {
                throw ValidationException::withMessages([
                    'role_name' => 'This role already has a permission for the exam.',
                ]);
            }

            return MarkEntryPermission::create([
                ...$values,
                'id' => (string) Str::uuid(),
                'exam_id' => $exam->id,
                'role_name' => $role,
                'active' => $values['active'] ?? true,
                'is_deleted' => false,
                'created_at' => now(),
            ]);
        });
    }

    public function update(string $school, string $id, array $data): MarkEntryPermission
    {
        return DB::transaction(function () use ($school, $id, $data) {
            [$exam, $permission] = $this->lockedPermission($school, $id);

            $values = Validator::make($data, [
                'active' => 'sometimes|boolean',
                'opens_at' => 'sometimes|nullable|date',
                'closes_at' => 'sometimes|nullable|date',
            ])->validate();

            $opens = array_key_exists('opens_at', $values)
                ? $values['opens_at'] : $permission->opens_at;
            $closes = array_key_exists('closes_at', $values)
                ? $values['closes_at'] : $permission->closes_at;

            $this->assertWindow($opens, $closes);

            if ($values['active'] ?? $permission->active) {
                $this->assertCanOpen($exam);
            }

            $permission->update($values);

            return $permission;
        });
    }

    public function revoke(string $school, string $id, ?string $actor): void
    {
        DB::transaction(function () use ($school, $id, $actor): void {
            [, $permission] = $this->lockedPermission($school, $id);

            if ($actor !== null && ! DB::table('users')
                ->where('school_id', $school)->where('id', $actor)->exists()) {
                throw ValidationException::withMessages([
                    'deleted_by' => 'The revocation actor must belong to this school.',
                ]);
            }

            $permission->update([
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $actor,
            ]);
        });
    }

    private function lockedPermission(string $school, string $id): array
    {
        app(ExamResultLifecycleService::class)->assertSchoolWritable($school);

        $candidate = DB::table('mark_entry_permissions as permission')
            ->join('exams as exam', 'exam.id', '=', 'permission.exam_id')
            ->where('exam.school_id', $school)
            ->where('exam.is_deleted', false)
            ->where('permission.is_deleted', false)
            ->where('permission.id', $id)
            ->select('permission.exam_id')->first();

        abort_if($candidate === null, 404, 'Mark entry permission not found.');

        // Match mark-writing lock order: school, exam, then permission.
        $exam = app(ExamResultLifecycleService::class)
            ->lockExamForWrite($school, $candidate->exam_id);

        $permission = MarkEntryPermission::current()
            ->where('exam_id', $exam->id)->whereKey($id)
            ->lockForUpdate()->first();

        abort_if($permission === null, 404, 'Mark entry permission not found.');

        return [$exam, $permission];
    }

    private function assertCanOpen(Exam $exam): void
    {
        if ($exam->status !== 'published' || ! $exam->active) {
            throw ValidationException::withMessages([
                'exam_id' => 'Mark entry can only be opened for an active published tenant exam.',
            ]);
        }
    }

    private function assertWindow(mixed $opens, mixed $closes): void
    {
        if ($opens !== null && $closes !== null
            && ! Carbon::parse($closes)->gt(Carbon::parse($opens))) {
            throw ValidationException::withMessages([
                'closes_at' => 'The closing time must be after the opening time.',
            ]);
        }
    }
}
