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

    public function records(User $user)
    {
        $learner = $this->access->learner($user);

        return HomeworkAssignmentLearner::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('availability_status', 'available')->whereHas('assignment', fn ($query) => $query->whereIn('status', ['published', 'closed', 'marking', 'marked'])->where('is_deleted', false))->with('assignment.resources', 'submissions.mark')->paginate(20);
    }

    public function record(User $user, string $assignment, bool $lock = false): HomeworkAssignmentLearner
    {
        $learner = $this->access->learner($user);
        $query = HomeworkAssignmentLearner::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('assignment_id', $assignment)->where('availability_status', 'available')->whereHas('assignment', fn ($homework) => $homework->whereIn('status', ['published', 'closed', 'marking', 'marked'])->where('is_deleted', false))->with('assignment');

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function draft(User $user, string $assignment, array $data): HomeworkSubmission
    {
        return DB::transaction(function () use ($user, $assignment, $data) {
            $record = $this->record($user, $assignment, true);
            $homework = $record->assignment;
            abort_unless($homework->status === 'published', 409, 'Homework is not open for submissions.');
            $current = $record->submissions()->where('submission_status', 'draft')->latest('attempt_number')->lockForUpdate()->first();
            if ($current) {
                $baseVersion = (int) ($data['base_version'] ?? $current->version);
                unset($data['base_version']);
                if ($baseVersion !== (int) $current->version) {
                    throw ValidationException::withMessages(['version' => 'The draft changed on the server. Refresh before editing.']);
                }
                $updated = HomeworkSubmission::withoutGlobalScopes()->whereKey($current->id)->where('version', $baseVersion)->where('submission_status', 'draft')->update($data + ['version' => $baseVersion + 1, 'autosaved_at' => now(), 'updated_at' => now()]);
                abort_unless($updated, 409, 'The draft changed before it could be saved.');

                return HomeworkSubmission::withoutGlobalScopes()->findOrFail($current->id);
            }
            unset($data['base_version']);
            $attempt = (int) $record->submissions()->max('attempt_number') + 1;
            if ($attempt > $homework->maximum_attempts) {
                throw ValidationException::withMessages(['attempt' => 'Maximum attempts reached.']);
            }
            if ($attempt > 1 && ! $homework->allow_resubmission) {
                throw ValidationException::withMessages(['attempt' => 'Resubmission is not allowed.']);
            }
            if ($attempt > 1 && ! in_array($record->submissions()->orderByDesc('attempt_number')->value('submission_status'), ['returned', 'resubmission_required'], true)) {
                throw ValidationException::withMessages(['attempt' => 'A teacher must authorize the next attempt.']);
            }

            return HomeworkSubmission::withoutGlobalScopes()->create($data + ['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'assignment_id' => $homework->id, 'assignment_learner_id' => $record->id, 'learner_id' => $record->learner_id, 'attempt_number' => $attempt, 'submission_status' => 'draft', 'version' => 1, 'autosaved_at' => now()]);
        });
    }

    public function submit(User $user, string $assignment): HomeworkSubmission
    {
        return DB::transaction(function () use ($user, $assignment) {
            $record = $this->record($user, $assignment, true);
            $homework = $record->assignment;
            abort_unless($homework->status === 'published', 409);
            $submission = $record->submissions()->where('submission_status', 'draft')->latest('attempt_number')->lockForUpdate()->firstOrFail();
            $late = now()->gt($homework->due_at);
            if ($late && ! $homework->allow_late_submission) {
                throw ValidationException::withMessages(['due_at' => 'Late submissions are closed.']);
            }
            $state = $late ? 'late' : ($submission->attempt_number > 1 ? 'resubmitted' : 'submitted');
            $submission->update(['submission_status' => $state, 'submitted_at' => now(), 'is_late' => $late, 'lateness_minutes' => $late ? $homework->due_at->diffInMinutes(now()) : null, 'version' => $submission->version + 1]);
            $record->update(['submission_status' => $state]);

            return $submission->fresh();
        });
    }

    public function withdraw(User $user, string $assignment): HomeworkSubmission
    {
        return DB::transaction(function () use ($user, $assignment) {
            $record = $this->record($user, $assignment, true);
            $submission = $record->submissions()->whereIn('submission_status', ['submitted', 'late', 'resubmitted'])->latest('attempt_number')->lockForUpdate()->firstOrFail();
            abort_if($submission->mark()->where('status', 'released')->exists(), 409, 'Released work cannot be withdrawn.');
            $submission->update(['submission_status' => 'withdrawn', 'withdrawn_at' => now(), 'version' => $submission->version + 1]);
            $record->update(['submission_status' => 'in_progress']);

            return $submission->fresh();
        });
    }

    public function resubmit(User $user, string $assignment, array $data = []): HomeworkSubmission
    {
        $record = $this->record($user, $assignment);
        $last = $record->submissions()->latest('attempt_number')->firstOrFail();
        abort_unless(in_array($last->submission_status, ['returned', 'resubmission_required'], true), 409, 'A teacher must return the work before resubmission.');

        return $this->draft($user, $assignment, $data);
    }

    public function history(User $user, string $assignment)
    {
        $record = $this->record($user, $assignment);

        return $record->submissions()->orderBy('attempt_number')->get()->map(function (HomeworkSubmission $submission) {
            return ['id' => $submission->id, 'attempt_number' => $submission->attempt_number, 'status' => $submission->submission_status, 'submitted_at' => $submission->submitted_at, 'is_late' => $submission->is_late, 'version' => $submission->version, 'feedback_available' => $submission->mark()->where('status', 'released')->exists()];
        });
    }

    public function safeUrl(string $url): string
    {
        return $this->links->safeUrl($url);
    }
}
