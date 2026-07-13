<?php

namespace App\Services\Homework;

use App\Models\HomeworkAssignmentLearner;
use App\Models\HomeworkSubmission;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeworkLearnerService
{
    public function __construct(private LearnerPortalAccessService $access, private LearningResourceService $links) {}

    public function records(User $u)
    {
        $l = $this->access->learner($u);

        return HomeworkAssignmentLearner::where('school_id', $u->school_id)->where('learner_id', $l->id)->where('availability_status', 'available')->whereHas('assignment', fn ($q) => $q->whereIn('status', ['published', 'closed', 'marking', 'marked']))->with('assignment.resources', 'submissions.mark')->paginate(20);
    }

    public function record(User $u, string $assignment, bool $lock = false): HomeworkAssignmentLearner
    {
        $l = $this->access->learner($u);
        $q = HomeworkAssignmentLearner::where('school_id', $u->school_id)->where('learner_id', $l->id)->where('assignment_id', $assignment)->where('availability_status', 'available')->with('assignment');

        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }

    public function draft(User $u, string $assignment, array $data): HomeworkSubmission
    {
        return DB::transaction(function () use ($u, $assignment, $data) {
            $r = $this->record($u, $assignment, true);
            $a = $r->assignment;
            abort_unless($a->status === 'published', 409);
            $current = $r->submissions()->where('submission_status', 'draft')->latest('attempt_number')->first();
            if ($current) {
                $current->update($data);

                return $current;
            } $attempt = (int) $r->submissions()->max('attempt_number') + 1;
            if ($attempt > $a->maximum_attempts) {
                throw ValidationException::withMessages(['attempt' => 'Maximum attempts reached.']);
            }if ($attempt > 1 && ! $a->allow_resubmission) {
                throw ValidationException::withMessages(['attempt' => 'Resubmission is not allowed.']);
            }

            return HomeworkSubmission::create($data + ['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'assignment_id' => $a->id, 'assignment_learner_id' => $r->id, 'learner_id' => $r->learner_id, 'attempt_number' => $attempt, 'submission_status' => 'draft']);
        });
    }

    public function submit(User $u, string $assignment): HomeworkSubmission
    {
        return DB::transaction(function () use ($u, $assignment) {
            $r = $this->record($u, $assignment, true);
            $a = $r->assignment;
            abort_unless($a->status === 'published', 409);
            $s = $r->submissions()->where('submission_status', 'draft')->latest('attempt_number')->lockForUpdate()->firstOrFail();
            $late = now()->gt($a->due_at);
            if ($late && ! $a->allow_late_submission) {
                throw ValidationException::withMessages(['due_at' => 'Late submissions are closed.']);
            }$s->update(['submission_status' => 'submitted', 'submitted_at' => now(), 'is_late' => $late, 'lateness_minutes' => $late ? $a->due_at->diffInMinutes(now()) : null]);
            $r->update(['submission_status' => $late ? 'late' : 'submitted']);

            return $s;
        });
    }

    public function safeUrl(string $url): string
    {
        return $this->links->safeUrl($url);
    }
}
