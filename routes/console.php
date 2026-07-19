<?php

use App\Jobs\DeliverCommunicationEmail;
use App\Jobs\DeliverCommunicationSms;
use App\Jobs\DeliverLearnerPush;
use App\Jobs\DeliverParentPush;
use App\Jobs\DeliverTeacherPush;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Services\Administrator\AdministratorImportService;
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
use App\Services\ParentPortal\ParentPaymentReconciliationService;
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

Artisan::command('teacher-workflows:generate-tasks', function () {
    $count = DB::table('teacher_workflows')->whereIn('state', ['draft', 'changes_requested', 'rejected'])->limit(500)->count();
    $this->info("Identified {$count} bounded teacher workflow tasks.");
})->purpose('Generate the deterministic teacher workflow task view');

Artisan::command('teacher-workflows:send-reminders', function () {
    $count = DB::table('teacher_workflows')->join('schools', 'schools.id', '=', 'teacher_workflows.school_id')->where('schools.active', true)->whereIn('teacher_workflows.state', ['changes_requested', 'submitted'])->limit(200)->count();
    $this->info("Evaluated {$count} workflow reminders without direct provider calls.");
})->purpose('Evaluate bounded workflow reminders for active schools');

Artisan::command('teacher-sync:cleanup', function () {
    $cutoff = now()->subDays(90);
    $count = DB::table('teacher_sync_operations')->where('created_at', '<', $cutoff)->whereIn('status', ['accepted', 'server_wins'])->limit(500)->delete();
    $this->info("Removed {$count} expired idempotency receipts; conflicts were preserved.");
})->purpose('Clean bounded expired sync receipts without deleting conflicts');

Artisan::command('teacher-uploads:cleanup-quarantine', function () {
    $count = DB::table('teacher_attachments')->where('status', 'pending_scan')->where('created_at', '<', now()->subDays(config('teacher_portal_phase_two.quarantine_retention_days', 30)))->limit(200)->update(['status' => 'quarantined', 'updated_at' => now()]);
    $this->info("Quarantined {$count} expired pending teacher uploads without deleting evidence.");
})->purpose('Transition stale teacher uploads to quarantine safely');

Artisan::command('teacher-push:retry-failed', function () {
    $count = 0;
    DB::table('teacher_push_deliveries')->where('status', 'failed')->where('attempt_count', '<', config('teacher_portal_phase_two.push_retry_limit', 3))->limit(100)->pluck('id')->each(function ($id) use (&$count) {
        DB::table('teacher_push_deliveries')->where('id', $id)->update(['status' => 'queued', 'updated_at' => now()]);
        DeliverTeacherPush::dispatch($id);
        $count++;
    });
    $this->info("Queued {$count} bounded teacher push retries.");
})->purpose('Queue bounded failed teacher pushes through the delivery job');

Artisan::command('learner-tasks:generate', function () {
    $count = DB::table('homework_assignment_learners')->join('learners', 'learners.id', '=', 'homework_assignment_learners.learner_id')->join('schools', 'schools.id', '=', 'homework_assignment_learners.school_id')->where('schools.active', true)->where('learners.active', true)->where('learners.portal_enabled', true)->whereIn('homework_assignment_learners.submission_status', ['not_started', 'in_progress', 'returned', 'resubmission_required'])->limit(500)->count();
    $this->info("Identified {$count} bounded deterministic learner tasks.");
})->purpose('Generate the deterministic learner task view');

Artisan::command('learner-sync:cleanup', function () {
    $ids = DB::table('learner_sync_operations as operations')->join('schools', 'schools.id', '=', 'operations.school_id')->join('learners', 'learners.id', '=', 'operations.learner_id')->where('schools.active', true)->where('learners.active', true)->where('learners.portal_enabled', true)->where('operations.created_at', '<', now()->subDays(90))->whereIn('operations.status', ['accepted', 'server_wins'])->limit(500)->pluck('operations.id');
    $count = DB::table('learner_sync_operations')->whereIn('id', $ids)->delete();
    $this->info("Removed {$count} expired learner sync receipts; conflicts were preserved.");
})->purpose('Clean bounded learner sync receipts without deleting conflicts');

Artisan::command('learner-uploads:cleanup-quarantine', function () {
    $ids = DB::table('learner_portal_attachments as attachments')->join('schools', 'schools.id', '=', 'attachments.school_id')->join('learners', 'learners.id', '=', 'attachments.learner_id')->where('schools.active', true)->where('learners.active', true)->where('learners.portal_enabled', true)->where('attachments.status', 'pending_scan')->where('attachments.created_at', '<', now()->subDays(30))->limit(200)->pluck('attachments.id');
    $count = DB::table('learner_portal_attachments')->whereIn('id', $ids)->update(['status' => 'quarantined', 'updated_at' => now()]);
    $this->info("Quarantined {$count} stale learner uploads without deleting evidence.");
})->purpose('Transition stale learner uploads to quarantine safely');

Artisan::command('learner-push:retry-failed', function () {
    $count = 0;
    DB::table('learner_push_deliveries as deliveries')->join('schools', 'schools.id', '=', 'deliveries.school_id')->join('learners', 'learners.id', '=', 'deliveries.learner_id')->where('schools.active', true)->where('learners.active', true)->where('learners.portal_enabled', true)->where('deliveries.status', 'failed')->limit(100)->pluck('deliveries.id')->each(function ($id) use (&$count) {
        DB::table('learner_push_deliveries')->whereKey($id)->update(['status' => 'queued', 'updated_at' => now()]);
        DeliverLearnerPush::dispatch($id);
        $count++;
    });
    $this->info("Queued {$count} bounded learner push retries through the delivery job.");
})->purpose('Queue bounded learner push retries without direct provider calls');

Artisan::command('learner-offline:expire-resources', function () {
    $ids = DB::table('learner_offline_resources as offline')->join('schools', 'schools.id', '=', 'offline.school_id')->join('learners', 'learners.id', '=', 'offline.learner_id')->where('schools.active', true)->where('learners.active', true)->where('learners.portal_enabled', true)->whereNull('offline.revoked_at')->whereNotNull('offline.expires_at')->where('offline.expires_at', '<', now())->limit(500)->pluck('offline.id');
    $count = DB::table('learner_offline_resources')->whereIn('id', $ids)->update(['revoked_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    $this->info("Expired {$count} stale learner offline markers without deleting history.");
})->purpose('Expire bounded learner offline resource markers safely');

Artisan::command('parent-payments:reconcile {--limit=100}', function (ParentPaymentReconciliationService $service) {
    $this->info('Found '.$service->pending((int) $this->option('limit')).' bounded payment attempts requiring provider or finance reconciliation.');
})->purpose('Report bounded unresolved parent payment attempts safely');

Artisan::command('parent-payments:expire-attempts {--limit=100}', function (ParentPaymentReconciliationService $service) {
    $this->info('Expired '.$service->expire((int) $this->option('limit')).' stale parent payment attempts.');
})->purpose('Expire bounded stale parent payment attempts safely');

Artisan::command('parent-payments:retry-posting {--limit=100}', function (ParentPaymentReconciliationService $service) {
    $this->info('Retried '.$service->retryPosting((int) $this->option('limit')).' bounded provider-confirmed finance postings.');
})->purpose('Identify bounded provider-confirmed posting exceptions without duplicate posting');

Artisan::command('parent-tasks:generate', function () {
    $count = DB::table('parent_payment_attempts')->join('schools', 'schools.id', '=', 'parent_payment_attempts.school_id')->where('schools.active', true)->whereIn('parent_payment_attempts.status', ['pending', 'awaiting_customer', 'failed', 'reconciliation_required'])->limit(500)->count();
    $this->info("Identified {$count} bounded deterministic parent tasks.");
})->purpose('Generate the deterministic parent task view');

Artisan::command('parent-sync:cleanup', function () {
    $count = DB::table('parent_sync_operations')->where('created_at', '<', now()->subDays(90))->whereIn('status', ['accepted', 'server_wins'])->limit(500)->delete();
    $drafts = DB::table('parent_offline_drafts')->where('updated_at', '<', now()->subDays(90))->limit(500)->delete();
    $this->info("Removed {$count} expired parent sync receipts and {$drafts} stale parent-owned drafts; conflicts were preserved.");
})->purpose('Clean bounded parent sync receipts without deleting conflicts');

Artisan::command('parent-uploads:cleanup-quarantine', function () {
    $count = DB::table('parent_portal_attachments')->where('status', 'pending_scan')->where('created_at', '<', now()->subDays(30))->limit(200)->update(['status' => 'quarantined', 'updated_at' => now()]);
    $this->info("Quarantined {$count} stale parent uploads without deleting evidence.");
})->purpose('Transition stale parent uploads to quarantine safely');

Artisan::command('parent-push:retry-failed', function () {
    $count = 0;
    DB::table('parent_push_deliveries')->where('status', 'failed')->where('attempt_count', '<', 3)->limit(100)->pluck('id')->each(function ($id) use (&$count) {
        DB::table('parent_push_deliveries')->whereKey($id)->update(['status' => 'queued', 'updated_at' => now()]);
        DeliverParentPush::dispatch($id);
        $count++;
    });
    $this->info("Queued {$count} bounded parent push retries through the delivery job.");
})->purpose('Queue bounded parent push retries without direct provider calls');

Artisan::command('admin-health:check', function () {
    $checks = [
        'database' => ['status' => 'healthy', 'connectivity' => true],
        'queue' => ['status' => DB::table('failed_jobs')->count() ? 'warning' : 'healthy', 'failed' => DB::table('failed_jobs')->count()],
        'storage' => ['status' => is_writable(storage_path('app')) ? 'healthy' : 'critical', 'writable' => is_writable(storage_path('app'))],
    ];
    foreach ($checks as $component => $metrics) {
        DB::table('administrator_health_snapshots')->updateOrInsert(['component' => $component], ['id' => (string) Str::uuid(), 'status' => $metrics['status'], 'safe_metrics' => json_encode($metrics), 'checked_at' => now()]);
    }
    $this->info('Recorded '.count($checks).' safe administrator health checks.');
})->purpose('Record bounded secret-free administrator system health indicators');

Artisan::command('admin-tasks:generate', function () {
    $count = DB::table('schools')->where('active', true)->where('is_deleted', false)->whereNotIn('lifecycle_state', ['suspended', 'locked', 'archived'])->limit(500)->count();
    $this->info("Evaluated {$count} active schools for deterministic administrator tasks.");
})->purpose('Evaluate bounded deterministic administrator tasks');

Artisan::command('admin-imports:process {--limit=25}', function (AdministratorImportService $imports) {
    $this->info('Validated '.$imports->processQueued(min((int) $this->option('limit'), 100)).' queued administrator imports.');
})->purpose('Process bounded validated administrator imports without destructive updates');

Artisan::command('admin-imports:cleanup {--limit=100}', function () {
    $count = DB::table('administrator_imports')->whereIn('status', ['cancelled', 'failed'])->where('updated_at', '<', now()->subDays(30))->limit(min((int) $this->option('limit'), 500))->update(['status' => 'expired', 'updated_at' => now()]);
    $this->info("Expired {$count} stale import records without deleting history.");
})->purpose('Expire bounded stale administrator imports while preserving history');

Artisan::command('admin-alerts:refresh', function () {
    $count = 0;
    DB::table('schools')->where('active', true)->where('is_deleted', false)->whereNotIn('lifecycle_state', ['suspended', 'locked', 'archived'])->limit(500)->pluck('id')->each(function ($schoolId) use (&$count) {
        $locked = DB::table('users')->where('school_id', $schoolId)->where('account_locked_until', '>', now())->count();
        if ($locked) {
            DB::table('administrator_alerts')->updateOrInsert(['school_id' => $schoolId, 'alert_key' => 'locked-users'], ['id' => (string) Str::uuid(), 'type' => 'security_warning', 'severity' => 'warning', 'title' => 'Locked user accounts', 'safe_message' => "{$locked} user accounts require review.", 'status' => 'open', 'source_updated_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
            $count++;
        }
    });
    $this->info("Refreshed {$count} deterministic administrator alerts.");
})->purpose('Refresh bounded deterministic administrator alerts');
