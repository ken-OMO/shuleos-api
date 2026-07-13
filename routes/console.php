<?php

use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Services\Homework\HomeworkAssignmentService;
use App\Services\Homework\HomeworkNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('homework:publish-scheduled', function (HomeworkAssignmentService $service) {
    $count = 0;
    HomeworkAssignment::withoutGlobalScopes()->where('status', 'scheduled')->where('is_deleted', false)->where('publish_at', '<=', now())->pluck('id')->each(function ($id) use ($service, &$count) {
        try {
            $assignment = HomeworkAssignment::withoutGlobalScopes()->findOrFail($id);
            $teacher = DB::table('teachers')->where('id', $assignment->teacher_id)->where('school_id', $assignment->school_id)->where('active', true)->where('is_deleted', false)->first();
            $active = $teacher && DB::table('teacher_assignments')->where('id', $assignment->teacher_assignment_id)->where('teacher_id', $teacher->id)->where('active', true)->where('is_deleted', false)->exists();
            if (! $active) {
                return;
            }$user = User::whereKey($teacher->user_id)->where('school_id', $assignment->school_id)->where('active', true)->first();
            if ($user) {
                $service->transition($user, $assignment->id, 'published');
                $count++;
            }
        } catch (Throwable $e) {
            report($e);
        }
    });
    $this->info("Published {$count} scheduled homework assignments.");
})->purpose('Publish due scheduled homework assignments safely');

Artisan::command('homework:send-reminders', function (HomeworkNotificationService $notifications) {
    $count = 0;
    $hours = (int) config('homework.due_soon_hours', 24);
    HomeworkAssignment::withoutGlobalScopes()->where('status', 'published')->where('is_deleted', false)->whereBetween('due_at', [now(), now()->addHours($hours)])->get()->each(function ($a) use ($notifications, &$count) {
        $count += $notifications->assignment($a, 'due_soon');
    });
    HomeworkAssignment::withoutGlobalScopes()->where('status', 'published')->where('is_deleted', false)->where('due_at', '<', now())->get()->each(function ($a) use ($notifications, &$count) {
        $count += $notifications->assignment($a, 'overdue');
    });
    $this->info("Created {$count} homework reminders.");
})->purpose('Create idempotent due and overdue homework notifications');
