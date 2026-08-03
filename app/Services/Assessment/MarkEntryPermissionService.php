<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\MarkEntryPermission;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarkEntryPermissionService
{
    public function create(array $d, string $school): MarkEntryPermission
    {
        $exam = Exam::current()->whereKey($d['exam_id'])->where('school_id', $school)->where('status', 'published')->where('active', true)->first();
        if (! $exam) {
            throw ValidationException::withMessages(['exam_id' => 'Mark entry can only be opened for an active published tenant exam.']);
        }$role = strtolower(trim($d['role_name']));
        if (MarkEntryPermission::current()->where('exam_id', $exam->id)->whereRaw('LOWER(role_name) = ?', [$role])->exists()) {
            throw ValidationException::withMessages(['role_name' => 'This role already has a permission for the exam.']);
        }

        return MarkEntryPermission::create([...$d, 'id' => (string) Str::uuid(), 'role_name' => $role, 'active' => $d['active'] ?? true, 'is_deleted' => false, 'created_at' => now()]);
    }
}
