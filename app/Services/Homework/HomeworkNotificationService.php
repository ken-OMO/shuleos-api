<?php

namespace App\Services\Homework;

use App\Models\HomeworkAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeworkNotificationService
{
    public function assignment(HomeworkAssignment $a, string $stage): int
    {
        $rows = DB::table('homework_assignment_learners')->join('learners', 'learners.id', '=', 'homework_assignment_learners.learner_id')->join('users', 'users.id', '=', 'learners.user_id')->where('homework_assignment_learners.assignment_id', $a->id)->where('users.school_id', $a->school_id)->where('users.active', true)->whereNotNull('learners.user_id')->select('homework_assignment_learners.id as record_id', 'users.id as user_id')->get();
        $n = 0;
        foreach ($rows as $row) {
            $n += $this->send($a, $row->user_id, $stage.':'.$a->id, $this->message($a, $stage), $row->record_id);
        }

        return $n;
    }

    public function learner(HomeworkAssignment $a, string $learnerId, string $stage): bool
    {
        $row = DB::table('learners')->join('users', 'users.id', '=', 'learners.user_id')->where('learners.id', $learnerId)->where('learners.school_id', $a->school_id)->where('users.active', true)->select('users.id')->first();

        return $row ? $this->send($a, $row->id, $stage.':'.$a->id.':'.$learnerId, $this->message($a, $stage)) : false;
    }

    private function send(HomeworkAssignment $a, string $user, string $key, string $message, ?string $record = null): bool
    {
        return DB::transaction(function () use ($a, $user, $key, $message, $record) {
            $inserted = DB::table('homework_notification_events')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $a->school_id, 'assignment_id' => $a->id, 'assignment_learner_id' => $record, 'user_id' => $user, 'event_key' => $key, 'created_at' => now()]);
            if (! $inserted) {
                return false;
            }DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'school_id' => $a->school_id, 'user_id' => $user, 'title' => 'Homework: '.$a->title, 'message' => $message, 'is_read' => false, 'created_at' => now()]);

            return true;
        });
    }

    private function message(HomeworkAssignment $a, string $stage): string
    {
        return match ($stage) {
            'published' => 'A new assignment is available and is due '.$a->due_at->format('Y-m-d H:i').'.','due_soon' => 'An assignment is due soon: '.$a->due_at->format('Y-m-d H:i').'.','overdue' => 'An assignment is overdue.','returned' => 'Your assignment submission was returned.','resubmission_requested' => 'A new submission attempt was requested.','released' => 'Homework feedback is now available.',default => 'Homework status has changed.'
        };
    }
}
