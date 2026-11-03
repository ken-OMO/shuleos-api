<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoardingReportOccurrenceService
{
    public function __construct(
        private BoardingReportingAccessService $access,
        private BoardingReportingAuditService $audit,
        private BoardingDailyReportService $reports,
    ) {}

    public function record(
        User $user,
        string $revisionId,
        int $expectedVersion,
        array $data
    ): array {
        return $this->write(
            $user, $revisionId, $expectedVersion, 'record', $data
        );
    }

    public function correct(
        User $user,
        string $revisionId,
        int $expectedVersion,
        string $eventId,
        int $expectedEventVersion,
        array $data,
        string $reason
    ): array {
        return $this->write(
            $user, $revisionId, $expectedVersion, 'correct', $data,
            $eventId, $expectedEventVersion, $reason
        );
    }

    public function void(
        User $user,
        string $revisionId,
        int $expectedVersion,
        string $eventId,
        int $expectedEventVersion,
        string $reason
    ): array {
        return $this->write(
            $user, $revisionId, $expectedVersion, 'void', [],
            $eventId, $expectedEventVersion, $reason
        );
    }

    public function read(User $user, string $reportId): array
    {
        // The report service applies school-wide or dated-assignment read scope.
        $report = $this->reports->read($user, $reportId);

        if ($report['snapshot'] !== null) {
            $report['occurrences'] = $report['snapshot']['occurrences'] ?? [];
        } else {
            $value = DB::selectOne(
                'SELECT boarding_report_occurrence_snapshot(?::uuid, ?::uuid, ?::integer) AS evidence',
                [$user->school_id, $reportId, $report['revision_number']]
            )->evidence;
            $report['occurrences'] = json_decode(
                $value, true, 512, JSON_THROW_ON_ERROR
            );
        }

        return $report;
    }

    private function write(
        User $user,
        string $revisionId,
        int $expectedVersion,
        string $operation,
        array $data,
        ?string $eventId = null,
        ?int $expectedEventVersion = null,
        ?string $reason = null
    ): array {
        return DB::transaction(function () use (
            $user, $revisionId, $expectedVersion, $operation,
            $data, $eventId, $expectedEventVersion, $reason
        ): array {
            $actor = $this->access->actor($user, true);
            $this->access->requirePermission($actor, 'record_boarding_operations');

            $identity = DB::table('boarding_daily_report_revisions')
                ->where('school_id', $actor->school_id)
                ->where('id', $revisionId)->first();
            abort_unless($identity !== null, 404);

            // Same lock order as the report lifecycle service.
            $report = DB::table('boarding_daily_reports')
                ->where('school_id', $actor->school_id)
                ->where('id', $identity->report_id)
                ->lockForUpdate()->first();
            abort_unless($report !== null, 404);

            $revision = DB::table('boarding_daily_report_revisions')
                ->where('school_id', $actor->school_id)
                ->where('id', $revisionId)->lockForUpdate()->first();
            abort_unless($revision !== null, 404);
            abort_unless(
                $expectedVersion > 0
                && (int) $revision->lock_version === $expectedVersion,
                409
            );

            abort_if(DB::table('boarding_daily_report_submissions')
                ->where('school_id', $actor->school_id)
                ->where('revision_id', $revisionId)->exists(), 409);

            $obligation = DB::table('boarding_daily_obligations')
                ->where('school_id', $actor->school_id)
                ->where('id', $report->obligation_id)->first();
            abort_unless($obligation !== null, 404);

            $this->access->assertReportDate($actor, $obligation);
            $assignment = $this->access->assignment($actor, $obligation);

            $previous = null;

            if ($operation !== 'record') {
                $previous = DB::table('boarding_report_occurrence_events')
                    ->where('school_id', $actor->school_id)
                    ->where('report_id', $report->id)
                    ->where('event_id', $eventId)
                    ->orderByDesc('version')->first();
                abort_unless($previous !== null, 404);
                abort_unless(
                    $expectedEventVersion !== null
                    && $expectedEventVersion > 0
                    && (int) $previous->version === $expectedEventVersion
                    && $previous->event_state === 'recorded',
                    409
                );

                $reason = trim($reason ?? '');
                validator(['reason' => $reason], [
                    'reason' => ['required', 'string', 'max:500'],
                ])->validate();
            }

            if ($operation === 'void') {
                $content = [
                    'category_id' => $previous->category_id,
                    'occurred_at' => $previous->occurred_at,
                    'description' => $previous->description,
                    'actions_taken' => $previous->actions_taken,
                    'follow_up_required' => (bool) $previous->follow_up_required,
                    'follow_up_notes' => $previous->follow_up_notes,
                ];
            } else {
                $content = $this->content($actor, $obligation, $data);
            }

            $id = (string) Str::uuid();
            $logicalId = $previous ? (string) $previous->event_id : (string) Str::uuid();
            $version = $previous ? (int) $previous->version + 1 : 1;

            DB::table('boarding_report_occurrence_events')->insert(array_merge(
                $content,
                [
                    'id' => $id,
                    'school_id' => $actor->school_id,
                    'report_id' => $report->id,
                    'revision_id' => $revisionId,
                    'event_id' => $logicalId,
                    'version' => $version,
                    'previous_id' => $previous?->id,
                    'event_state' => $operation === 'void' ? 'voided' : 'recorded',
                    'reason' => $operation === 'record' ? null : $reason,
                    'recorded_by' => $actor->id,
                    'assignment_id' => $assignment->id,
                ]
            ));

            // The database trigger increments the draft version and contributor evidence.
            $this->audit->record(
                $actor,
                'report_occurrence_'.$operation,
                'boarding_report_occurrence_events',
                $id,
                $previous ? ['event_id' => $logicalId, 'version' => (int) $previous->version] : [],
                [
                    'event_id' => $logicalId,
                    'version' => $version,
                    'revision_id' => $revisionId,
                    'reason' => $operation === 'record' ? null : $reason,
                ]
            );

            return $this->read($actor, (string) $report->id);
        });
    }

    private function content(User $actor, object $obligation, array $data): array
    {
        foreach (['description', 'actions_taken', 'follow_up_notes'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        $validated = validator($data, [
            'category_id' => ['required', 'uuid'],
            'occurred_at' => [
                'required', 'string', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
            ],
            'description' => ['required', 'string', 'max:5000'],
            'actions_taken' => ['present', 'string', 'max:5000'],
            'follow_up_required' => ['required', 'boolean'],
            'follow_up_notes' => ['present', 'string', 'max:2000'],
            'learner_id' => ['prohibited'],
            'learner_ids' => ['prohibited'],
            'school_id' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'recorded_by' => ['prohibited'],
        ])->validate();

        $category = DB::table('boarding_occurrence_categories')
            ->where('school_id', $actor->school_id)
            ->where('id', $validated['category_id'])->first();
        abort_unless($category !== null, 404);

        if (! $category->active) {
            throw ValidationException::withMessages([
                'category_id' => 'Select an active occurrence category.',
            ]);
        }

        $instant = CarbonImmutable::parse($validated['occurred_at']);

        if ($instant->greaterThan(CarbonImmutable::now())
            || $instant->setTimezone($obligation->timezone)->toDateString()
                !== $obligation->reporting_date) {
            throw ValidationException::withMessages([
                'occurred_at' => 'Use a nonfuture instant within the local reporting date.',
            ]);
        }

        if ((bool) $validated['follow_up_required']
            && trim($validated['follow_up_notes']) === '') {
            throw ValidationException::withMessages([
                'follow_up_notes' => 'Describe the required follow-up.',
            ]);
        }

        return [
            'category_id' => $validated['category_id'],
            'occurred_at' => $instant->utc()->format('Y-m-d H:i:s.uP'),
            'description' => $validated['description'],
            'actions_taken' => $validated['actions_taken'],
            'follow_up_required' => (bool) $validated['follow_up_required'],
            'follow_up_notes' => $validated['follow_up_notes'],
        ];
    }
}
