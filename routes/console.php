<?php

use App\Jobs\DeliverCommunicationEmail;
use App\Jobs\DeliverCommunicationSms;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Services\Attendance\AttendanceIntelligenceService;
use App\Services\Communication\CommunicationDigestService;
use App\Services\Communication\CommunicationSandboxSmokeService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\RecurringCommunicationService;
use App\Services\Communication\ScheduledCommunicationDispatchService;
use App\Services\Finance\FinanceArrearsService;
use App\Services\Finance\FinanceNotificationService;
use App\Services\Homework\HomeworkAssignmentService;
use App\Services\Homework\HomeworkNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

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

Artisan::command('attendance:generate-risk-flags', function (AttendanceIntelligenceService $service) {
    $this->info('Generated or refreshed '.$service->generate().' explainable attendance risk flags.');
})->purpose('Generate idempotent attendance risk flags from finalized records');

Artisan::command('finance:calculate-arrears {--academic-year=} {--term=} {--school=}', function (FinanceArrearsService $service) {
    $terms = DB::table('terms')->when($this->option('term'), fn ($query, $term) => $query->where('id', $term))->when($this->option('academic-year'), fn ($query, $year) => $query->where('academic_year_id', $year))->when($this->option('school'), fn ($query, $school) => $query->where('school_id', $school))->get();
    $count = 0;
    foreach ($terms as $term) {
        $actor = User::where('school_id', $term->school_id)->where('active', true)->where('is_deleted', false)->orderBy('created_at')->first();
        if ($actor) {
            $count += $service->calculate($actor, $term->academic_year_id, $term->id);
        }
    }
    $this->info("Calculated or refreshed {$count} arrears snapshots.");
})->purpose('Calculate idempotent learner arrears snapshots');

Artisan::command('finance:send-reminders {--school=}', function (FinanceNotificationService $service) {
    $count = 0;
    $schools = DB::table('finance_settings')->where('finance_reminders_enabled', true)->when($this->option('school'), fn ($query, $school) => $query->where('school_id', $school))->pluck('school_id');
    foreach ($schools as $schoolId) {
        $actor = User::where('school_id', $schoolId)->where('active', true)->where('is_deleted', false)->orderBy('created_at')->first();
        if ($actor) {
            $count += $service->reminders($actor);
        }
    }
    $this->info("Created {$count} finance reminders.");
})->purpose('Create idempotent fee-plan due and overdue portal reminders');

Artisan::command('communications:dispatch-scheduled', function (CommunicationService $service, RecurringCommunicationService $recurring, ScheduledCommunicationDispatchService $scheduled) {
    $sent = $scheduled->dispatchDue($service);
    $sent += $recurring->dispatchDue($service);
    $this->info("Dispatched {$sent} scheduled communications.");
})->purpose('Dispatch due approved communications idempotently');

Artisan::command('communications:sandbox-smoke {--email=} {--cleanup}', function (CommunicationSandboxSmokeService $smoke) {
    try {
        $report = $smoke->run((string) $this->option('email'), (bool) $this->option('cleanup'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($report['result'] ?? 'FAIL') === 'PASS' ? Command::SUCCESS : Command::FAILURE;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Run a guarded local-only communication workflow smoke test');

Artisan::command('communications:generate-digests', function (CommunicationDigestService $digests) {
    $this->info('Generated '.$digests->generate().' idempotent digest runs.');
})->purpose('Generate bounded, digest-safe communication runs');

Artisan::command('communications:retry-failed', function () {
    $count = 0;
    DB::table('communication_deliveries')->whereIn('status', ['queued', 'failed'])->where('failure_code', 'temporary_provider_failure')->where('attempt_count', '<', config('communication.email.retry_limit', 3))->orderBy('updated_at')->limit(config('communication.scheduler_batch_size', 100))->get()->each(function ($delivery) use (&$count) {
        if ($delivery->channel === 'email') {
            DeliverCommunicationEmail::dispatch($delivery->id);
            $count++;
        } elseif ($delivery->channel === 'sms') {
            DeliverCommunicationSms::dispatch($delivery->id);
            $count++;
        }
    });
    $this->info("Queued {$count} temporary delivery failures for retry.");
})->purpose('Retry bounded temporary communication failures');

Artisan::command('communications:reconcile-deliveries', function () {
    $count = 0;
    DB::table('communications')->whereIn('status', ['sent', 'partially_failed'])->orderBy('updated_at')->limit(config('communication.scheduler_batch_size', 100))->pluck('id')->each(function ($id) use (&$count) {
        $deliveries = DB::table('communication_deliveries')->where('communication_id', $id);
        $status = (clone $deliveries)->whereIn('status', ['failed', 'bounced', 'complained'])->exists() ? 'partially_failed' : 'sent';
        $count += DB::table('communications')->where('id', $id)->where('status', '<>', $status)->update(['status' => $status, 'updated_at' => now()]);
    });
    $this->info("Reconciled {$count} communication aggregate statuses.");
})->purpose('Reconcile communication aggregate status from channel deliveries');

Artisan::command('communications:cleanup', function () {
    $draftCutoff = now()->subDays(config('communication.draft_retention_days', 30));
    $expiredDrafts = DB::table('communications')->whereIn('status', ['draft', 'rejected'])->where('updated_at', '<', $draftCutoff)->pluck('id');
    $expiredAnnouncements = DB::table('communications')->where('communication_type', 'announcement')->where('status', 'sent')->whereNotNull('expires_at')->where('expires_at', '<=', now())->pluck('id');
    $ids = $expiredDrafts->merge($expiredAnnouncements)->unique();

    foreach ($ids as $id) {
        $communication = DB::table('communications')->where('id', $id)->first();
        if (! $communication) {
            continue;
        }
        DB::transaction(function () use ($communication) {
            DB::table('communications')->where('id', $communication->id)->where('status', $communication->status)->update(['status' => 'expired', 'updated_at' => now()]);
            DB::table('communication_audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $communication->school_id,
                'communication_id' => $communication->id,
                'actor_user_id' => null,
                'action' => 'retention_expired',
                'entity_type' => 'communication',
                'entity_id' => $communication->id,
                'metadata' => json_encode(['previous_status' => $communication->status]),
                'created_at' => now(),
            ]);
        });
    }

    $this->info('Expired '.$ids->count().' communications without deleting audit or delivery history.');
})->purpose('Apply non-destructive communication retention rules');
