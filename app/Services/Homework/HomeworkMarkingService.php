<?php

namespace App\Services\Homework;

use App\Models\HomeworkSubmission;
use App\Models\HomeworkSubmissionMark;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeworkMarkingService
{
    public function __construct(private HomeworkAssignmentService $assignments) {}

    public function mark(User $u, string $assignment, string $submission, array $data): HomeworkSubmissionMark
    {
        return DB::transaction(function () use ($u, $assignment, $submission, $data) {
            $a = $this->assignments->ownQuery($u)->whereKey($assignment)->firstOrFail();
            $s = HomeworkSubmission::whereKey($submission)->where('school_id', $u->school_id)->where('assignment_id', $a->id)->where('submission_status', 'submitted')->firstOrFail();
            $raw = isset($data['raw_score']) ? (float) $data['raw_score'] : null;
            if ($raw !== null && ($a->total_marks === null || $raw > (float) $a->total_marks || $raw < 0)) {
                throw ValidationException::withMessages(['raw_score' => 'Score is outside assignment limits.']);
            }$penalty = 0.0;
            if ($s->is_late && $raw !== null) {
                $value = (float) ($a->late_penalty_value ?? 0);
                $penalty = $a->late_penalty_type === 'percentage' ? $raw * $value / 100 : ($a->late_penalty_type === 'fixed_marks' ? $value : 0);
            }$final = $raw === null ? null : max(0, $raw - $penalty);
            $values = ['school_id' => $u->school_id, 'assignment_id' => $a->id, 'learner_id' => $s->learner_id, 'raw_score' => $raw, 'percentage' => $raw !== null && $a->total_marks > 0 ? round($raw / (float) $a->total_marks * 100, 2) : null, 'competency_level' => $data['competency_level'] ?? null, 'teacher_feedback' => $data['teacher_feedback'] ?? null, 'private_teacher_notes' => $data['private_teacher_notes'] ?? null, 'marked_by' => $u->id, 'marked_at' => now(), 'status' => $data['status'] ?? 'marked', 'late_penalty_applied' => $penalty, 'final_score' => $final, 'updated_at' => now()];
            $m = HomeworkSubmissionMark::where('submission_id', $s->id)->first();
            if ($m) {
                $m->update($values);
            } else {
                $m = HomeworkSubmissionMark::create($values + ['id' => (string) Str::uuid(), 'submission_id' => $s->id]);
            }$this->assignments->audit($a, 'marked', $u->id, ['submission_id' => $s->id]);

            return $m;
        });
    }

    public function release(User $u, string $assignment, string $submission): HomeworkSubmissionMark
    {
        return DB::transaction(function () use ($u, $assignment, $submission) {
            $a = $this->assignments->ownQuery($u)->whereKey($assignment)->firstOrFail();
            $m = HomeworkSubmissionMark::where('assignment_id', $a->id)->where('submission_id', $submission)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($m->status, ['marked', 'moderated'], true), 409);
            $m->update(['status' => 'released', 'released_at' => now()]);
            $this->assignments->audit($a, 'feedback_released', $u->id, ['submission_id' => $submission]);

            return $m;
        });
    }
}
