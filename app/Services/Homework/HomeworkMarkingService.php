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
    public function __construct(private HomeworkAssignmentService $assignments, private HomeworkNotificationService $notifications) {}

    public function mark(User $u, string $assignment, string $submission, array $data): HomeworkSubmissionMark
    {
        return DB::transaction(function () use ($u, $assignment, $submission, $data) {
            $a = $this->assignments->ownQuery($u)->whereKey($assignment)->firstOrFail();
            $s = HomeworkSubmission::whereKey($submission)->where('school_id', $u->school_id)->where('assignment_id', $a->id)->whereIn('submission_status', ['submitted', 'late', 'resubmitted'])->firstOrFail();
            $existing = HomeworkSubmissionMark::where('submission_id', $s->id)->lockForUpdate()->first();
            if ($existing?->status === 'released' && empty($data['revision_reason'])) {
                throw ValidationException::withMessages(['revision_reason' => 'A revision reason is required after release.']);
            }
            if (in_array($a->grading_mode, ['rubric', 'competency'], true)) {
                $criteria = DB::table('homework_rubric_criteria')->join('homework_rubrics', 'homework_rubrics.id', '=', 'homework_rubric_criteria.rubric_id')->where('homework_rubrics.assignment_id', $a->id)->select('homework_rubric_criteria.*')->get();
                $scores = collect($data['rubric_scores'] ?? []);
                if (($data['status'] ?? 'marked') !== 'draft' && $scores->count() !== $criteria->count()) {
                    throw ValidationException::withMessages(['rubric_scores' => 'Every rubric criterion must be scored.']);
                }
                $raw = 0.0;
                foreach ($scores as $score) {
                    $criterion = $criteria->firstWhere('id', $score['criterion_id']) ?: throw ValidationException::withMessages(['rubric_scores' => 'Invalid criterion.']);
                    $points = (float) ($score['points_awarded'] ?? 0);
                    if (! empty($score['level_id'])) {
                        $level = DB::table('homework_rubric_levels')->where('id', $score['level_id'])->where('criterion_id', $criterion->id)->first() ?: throw ValidationException::withMessages(['rubric_scores' => 'Level does not belong to criterion.']);
                        if (isset($score['points_awarded']) && abs($points - (float) $level->points) > .001) {
                            throw ValidationException::withMessages(['rubric_scores' => 'Selected level and points are inconsistent.']);
                        } $points = (float) $level->points;
                    } if ($points < 0 || $points > (float) $criterion->maximum_points) {
                        throw ValidationException::withMessages(['rubric_scores' => 'Points exceed criterion maximum.']);
                    } $raw += $points;
                }
                $data['raw_score'] = $raw;
            }
            $raw = isset($data['raw_score']) ? (float) $data['raw_score'] : null;
            if ($raw !== null && ($a->total_marks === null || $raw > (float) $a->total_marks || $raw < 0)) {
                throw ValidationException::withMessages(['raw_score' => 'Score is outside assignment limits.']);
            }$penalty = 0.0;
            if ($s->is_late && $raw !== null) {
                $value = (float) ($a->late_penalty_value ?? 0);
                $penalty = $a->late_penalty_type === 'percentage' ? $raw * $value / 100 : ($a->late_penalty_type === 'fixed_marks' ? $value : 0);
            }$final = $raw === null ? null : max(0, $raw - $penalty);
            $values = ['school_id' => $u->school_id, 'assignment_id' => $a->id, 'learner_id' => $s->learner_id, 'raw_score' => $raw, 'percentage' => $raw !== null && $a->total_marks > 0 ? round($raw / (float) $a->total_marks * 100, 2) : null, 'competency_level' => $data['competency_level'] ?? null, 'teacher_feedback' => $data['teacher_feedback'] ?? null, 'private_teacher_notes' => $data['private_teacher_notes'] ?? null, 'marked_by' => $u->id, 'marked_at' => now(), 'status' => $data['status'] ?? 'marked', 'late_penalty_applied' => $penalty, 'final_score' => $final, 'updated_at' => now()];
            $m = $existing;
            if ($m) {
                if ($m->status === 'released') {
                    DB::table('homework_submission_mark_revisions')->insert(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'submission_mark_id' => $m->id, 'assignment_id' => $a->id, 'submission_id' => $s->id, 'learner_id' => $s->learner_id, 'previous_raw_score' => $m->raw_score, 'new_raw_score' => $raw, 'previous_final_score' => $m->final_score, 'new_final_score' => $final, 'previous_competency_level' => $m->competency_level, 'new_competency_level' => $data['competency_level'] ?? null, 'previous_feedback' => $m->teacher_feedback, 'new_feedback' => $data['teacher_feedback'] ?? null, 'revision_reason' => $data['revision_reason'], 'revised_by' => $u->id, 'revised_at' => now()]);
                }
                $m->update($values);
            } else {
                $m = HomeworkSubmissionMark::create($values + ['id' => (string) Str::uuid(), 'submission_id' => $s->id]);
            }
            if (isset($scores)) {
                DB::table('homework_submission_rubric_scores')->where('submission_mark_id', $m->id)->delete();
                foreach ($scores as $score) {
                    DB::table('homework_submission_rubric_scores')->insert(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'submission_mark_id' => $m->id, 'criterion_id' => $score['criterion_id'], 'level_id' => $score['level_id'] ?? null, 'points_awarded' => $score['points_awarded'] ?? null, 'comment' => $score['comment'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->assignments->audit($a, 'marked', $u->id, ['submission_id' => $s->id]);

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
            $this->notifications->learner($a, $m->learner_id, 'released');

            return $m;
        });
    }

    public function returnSubmission(User $u, string $assignment, string $submission, string $reason, bool $resubmit = false): HomeworkSubmission
    {
        return DB::transaction(function () use ($u, $assignment, $submission, $reason, $resubmit) {
            $a = $this->assignments->ownQuery($u)->whereKey($assignment)->firstOrFail();
            $s = HomeworkSubmission::whereKey($submission)->where('assignment_id', $a->id)->where('school_id', $u->school_id)->lockForUpdate()->firstOrFail();
            if ($resubmit) {
                abort_unless($a->allow_resubmission, 422);
                abort_if($s->attempt_number >= $a->maximum_attempts, 422, 'Maximum attempts reached.');
            }$status = $resubmit ? 'resubmission_required' : 'returned';
            $s->update(['submission_status' => $status]);
            $s->assignmentLearner()->update(['submission_status' => $status]);
            $this->assignments->audit($a, $status, $u->id, ['submission_id' => $s->id, 'reason' => $reason]);
            $this->notifications->learner($a, $s->learner_id, $resubmit ? 'resubmission_requested' : 'returned');

            return $s;
        });
    }
}
