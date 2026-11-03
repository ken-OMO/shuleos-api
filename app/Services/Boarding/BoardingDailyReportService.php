<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoardingDailyReportService
{
    public function __construct(
        private BoardingReportingAccessService $access,
        private BoardingReportingAuditService $audit,
    ) {}

    public function open(User $user, string $obligationId): array
    {
        return DB::transaction(function () use ($user, $obligationId): array {
            $actor = $this->writer($user);
            $obligation = $this->obligation($actor, $obligationId);
            $this->access->assertReportDate($actor, $obligation);
            $assignment = $this->access->assignment($actor, $obligation);

            $hostel = DB::table('hostels')->where('school_id', $actor->school_id)
                ->where('id', $obligation->hostel_id)
                ->where('active', true)->where('is_deleted', false)->first();
            abort_unless($hostel !== null, 404);

            $report = DB::table('boarding_daily_reports')
                ->where('school_id', $actor->school_id)
                ->where('obligation_id', $obligationId)->lockForUpdate()->first();

            if (! $report) {
                $id = (string) Str::uuid();
                DB::table('boarding_daily_reports')->insert([
                    'id' => $id,
                    'school_id' => $actor->school_id,
                    'obligation_id' => $obligationId,
                    'created_by' => $actor->id,
                ]);
                $report = $this->report($actor, $id, true);
                $revision = $this->createRevision($actor, $report, $assignment);
                $this->audit->record(
                    $actor, 'report_opened', 'boarding_daily_reports', $id,
                    [], ['revision_id' => $revision]
                );
            }

            return $this->read($actor, (string) $report->id);
        });
    }

    public function updateDraft(
        User $user,
        string $revisionId,
        int $expectedVersion,
        array $data
    ): array {
        $data = validator($data, [
            'summary' => 'present|string|max:10000',
            'no_occurrences' => 'required|boolean',
        ])->validate();

        return DB::transaction(function () use (
            $user, $revisionId, $expectedVersion, $data
        ): array {
            $actor = $this->writer($user);
            [$report, $revision, $obligation] = $this->lockedRevision($actor, $revisionId);
            $this->assertVersion($revision, $expectedVersion);
            $this->assertDraft($actor, $revision);
            $this->access->assertReportDate($actor, $obligation);
            $assignment = $this->access->assignment($actor, $obligation);

            DB::table('boarding_daily_report_revisions')
                ->where('school_id', $actor->school_id)->where('id', $revisionId)
                ->where('lock_version', $expectedVersion)->update([
                    'summary' => $data['summary'],
                    'no_occurrences' => (bool) $data['no_occurrences'],
                    'lock_version' => $expectedVersion + 1,
                    'updated_by' => $actor->id,
                    'updated_assignment_id' => $assignment->id,
                ]);

            $this->audit->record(
                $actor, 'report_draft_updated', 'boarding_daily_report_revisions', $revisionId,
                [
                    'summary' => $revision->summary,
                    'no_occurrences' => (bool) $revision->no_occurrences,
                    'version' => $expectedVersion,
                ],
                [
                    'summary' => $data['summary'],
                    'no_occurrences' => (bool) $data['no_occurrences'],
                    'version' => $expectedVersion + 1,
                ]
            );

            return $this->read($actor, (string) $report->id);
        });
    }

    public function submit(User $user, string $revisionId, int $expectedVersion): array
    {
        return DB::transaction(function () use ($user, $revisionId, $expectedVersion): array {
            $actor = $this->writer($user);
            [$report, $revision, $obligation] = $this->lockedRevision($actor, $revisionId);
            $this->assertVersion($revision, $expectedVersion);
            $this->access->assertReportDate($actor, $obligation);
            $assignment = $this->access->assignment($actor, $obligation);

            $existing = DB::table('boarding_daily_report_submissions')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $revisionId)->first();

            if ($existing) {
                return $this->read($actor, (string) $report->id);
            }

            if (trim($revision->summary) === '') {
                throw ValidationException::withMessages([
                    'summary' => 'Provide a report summary before submission.',
                ]);
            }

            $payload = DB::selectOne(
                'SELECT public.boarding_report_occurrence_snapshot(?, ?, ?) AS payload',
                [$actor->school_id, $report->id, $revision->revision_number]
            )->payload;
            $events = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $hasOccurrences = collect($events)
                ->contains(fn (array $event): bool => $event['event_state'] === 'recorded');

            if ((bool) $revision->no_occurrences === $hasOccurrences) {
                throw ValidationException::withMessages([
                    'no_occurrences' => 'The declaration must agree with the recorded occurrences.',
                ]);
            }

            $id = (string) Str::uuid();
            DB::table('boarding_daily_report_submissions')->insert([
                'id' => $id,
                'school_id' => $actor->school_id,
                'revision_id' => $revisionId,
                'submitted_by' => $actor->id,
                'assignment_id' => $assignment->id,
            ]);

            $this->audit->record(
                $actor, 'report_submitted', 'boarding_daily_report_submissions', $id,
                [], ['report_id' => $report->id, 'revision_id' => $revisionId]
            );

            return $this->read($actor, (string) $report->id);
        });
    }

    public function openCorrection(User $user, string $reportId): array
    {
        return DB::transaction(function () use ($user, $reportId): array {
            $actor = $this->writer($user);
            $report = $this->report($actor, $reportId, true);
            $obligation = $this->obligation($actor, (string) $report->obligation_id);
            $this->access->assertReportDate($actor, $obligation);
            $assignment = $this->access->assignment($actor, $obligation);
            $previous = $this->latest($actor, $reportId);

            $submission = DB::table('boarding_daily_report_submissions')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $previous->id)->first();

            if (! $submission && (int) $previous->revision_number > 1) {
                return $this->read($actor, $reportId);
            }

            $review = DB::table('boarding_daily_report_reviews')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $previous->id)->first();
            abort_unless($review && $review->decision === 'changes_requested', 409);

            $id = $this->createRevision($actor, $report, $assignment, $previous);
            $this->audit->record(
                $actor, 'report_correction_opened', 'boarding_daily_report_revisions', $id,
                [], ['previous_revision_id' => $previous->id, 'report_id' => $reportId]
            );

            return $this->read($actor, $reportId);
        });
    }

    public function review(
        User $user,
        string $submissionId,
        string $decision,
        ?string $comment = null
    ): array {
        $comment = $comment === null ? null : trim($comment);
        validator(
            ['decision' => $decision, 'comment' => $comment],
            [
                'decision' => 'required|in:reviewed,changes_requested',
                'comment' => $decision === 'changes_requested'
                    ? ['required', 'string', 'max:4000', 'regex:/\S/']
                    : 'nullable|string|max:4000',
            ]
        )->validate();

        return DB::transaction(function () use (
            $user, $submissionId, $decision, $comment
        ): array {
            $actor = $this->access->actor($user, true);
            $this->access->requirePermission($actor, 'review_boarding_reports');

            $submission = DB::table('boarding_daily_report_submissions')
                ->where('school_id', $actor->school_id)->where('id', $submissionId)->first();
            abort_unless($submission !== null, 404);

            [$report, $revision] = $this->lockedRevision($actor, (string) $submission->revision_id);

            $existing = DB::table('boarding_daily_report_reviews')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $revision->id)->first();

            if ($existing) {
                abort_unless(
                    (string) $existing->reviewed_by === (string) $actor->id
                    && $existing->decision === $decision
                    && $existing->comment === $comment,
                    409
                );

                return $this->read($actor, (string) $report->id);
            }

            abort_if(DB::table('boarding_daily_report_contributors')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $revision->id)
                ->where('user_id', $actor->id)->exists(), 403);

            $id = (string) Str::uuid();
            DB::table('boarding_daily_report_reviews')->insert([
                'id' => $id,
                'school_id' => $actor->school_id,
                'revision_id' => $revision->id,
                'submission_id' => $submissionId,
                'reviewed_by' => $actor->id,
                'decision' => $decision,
                'comment' => $comment,
            ]);

            $this->audit->record(
                $actor, 'report_reviewed', 'boarding_daily_report_reviews', $id,
                [], ['submission_id' => $submissionId, 'decision' => $decision]
            );

            return $this->read($actor, (string) $report->id);
        });
    }

    public function read(User $user, string $reportId): array
    {
        $actor = $this->access->actor($user);
        $report = $this->report($actor, $reportId);
        $obligation = $this->obligation($actor, (string) $report->obligation_id);

        if (! $this->access->schoolWideReader($actor)) {
            $this->access->requirePermission($actor, 'record_boarding_operations');
            $this->access->assignment($actor, $obligation);
        }

        $revision = $this->latest($actor, $reportId);
        $submission = DB::table('boarding_daily_report_submissions')
            ->where('school_id', $actor->school_id)
            ->where('revision_id', $revision->id)->first();
        $review = DB::table('boarding_daily_report_reviews')
            ->where('school_id', $actor->school_id)
            ->where('revision_id', $revision->id)->first();

        $firstSubmitted = DB::table('boarding_daily_report_submissions as submission')
            ->join('boarding_daily_report_revisions as revision', function ($join): void {
                $join->on('revision.school_id', '=', 'submission.school_id')
                    ->on('revision.id', '=', 'submission.revision_id');
            })
            ->where('revision.school_id', $actor->school_id)
            ->where('revision.report_id', $reportId)->min('submission.submitted_at');

        $deadline = CarbonImmutable::parse($obligation->due_at);

        return [
            'report_id' => (string) $report->id,
            'obligation_id' => (string) $obligation->id,
            'hostel_id' => (string) $obligation->hostel_id,
            'reporting_date' => $obligation->reporting_date,
            'deadline_at' => $deadline->toIso8601String(),
            'revision_id' => (string) $revision->id,
            'revision_number' => (int) $revision->revision_number,
            'version' => (int) $revision->lock_version,
            'summary' => $revision->summary,
            'no_occurrences' => (bool) $revision->no_occurrences,
            'state' => $review->decision ?? ($submission ? 'submitted' : 'draft'),
            'submission_id' => $submission?->id,
            'snapshot' => $submission
                ? json_decode($submission->snapshot, true, 512, JSON_THROW_ON_ERROR) : null,
            'review' => $review ? [
                'id' => $review->id,
                'decision' => $review->decision,
                'comment' => $review->comment,
                'reviewed_at' => $review->reviewed_at,
            ] : null,
            'first_submitted_at' => $firstSubmitted,
            'late' => $firstSubmitted !== null
                && CarbonImmutable::parse($firstSubmitted)->greaterThan($deadline),
            'overdue' => $firstSubmitted === null
                && CarbonImmutable::now()->greaterThan($deadline),
        ];
    }

    private function writer(User $user): User
    {
        $actor = $this->access->actor($user, true);
        $this->access->requirePermission($actor, 'record_boarding_operations');

        return $actor;
    }

    private function obligation(User $actor, string $id): object
    {
        $row = DB::table('boarding_daily_obligations')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function report(User $actor, string $id, bool $lock = false): object
    {
        $query = DB::table('boarding_daily_reports')
            ->where('school_id', $actor->school_id)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function latest(User $actor, string $reportId): object
    {
        $row = DB::table('boarding_daily_report_revisions')
            ->where('school_id', $actor->school_id)->where('report_id', $reportId)
            ->orderByDesc('revision_number')->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function lockedRevision(User $actor, string $id): array
    {
        $row = DB::table('boarding_daily_report_revisions')
            ->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        $report = $this->report($actor, (string) $row->report_id, true);
        $revision = DB::table('boarding_daily_report_revisions')
            ->where('school_id', $actor->school_id)->where('id', $id)
            ->lockForUpdate()->first();
        abort_unless($revision !== null, 404);

        return [
            $report, $revision,
            $this->obligation($actor, (string) $report->obligation_id),
        ];
    }

    private function assertVersion(object $revision, int $expected): void
    {
        abort_unless($expected > 0 && (int) $revision->lock_version === $expected, 409);
    }

    private function assertDraft(User $actor, object $revision): void
    {
        abort_if(DB::table('boarding_daily_report_submissions')
            ->where('school_id', $actor->school_id)
            ->where('revision_id', $revision->id)->exists(), 409);
    }

    private function createRevision(
        User $actor,
        object $report,
        object $assignment,
        ?object $previous = null
    ): string {
        $id = (string) Str::uuid();
        DB::table('boarding_daily_report_revisions')->insert([
            'id' => $id,
            'school_id' => $actor->school_id,
            'report_id' => $report->id,
            'revision_number' => $previous ? (int) $previous->revision_number + 1 : 1,
            'summary' => $previous->summary ?? '',
            'no_occurrences' => $previous ? (bool) $previous->no_occurrences : false,
            'created_by' => $actor->id,
            'created_assignment_id' => $assignment->id,
            'updated_by' => $actor->id,
            'updated_assignment_id' => $assignment->id,
        ]);

        return $id;
    }
}
