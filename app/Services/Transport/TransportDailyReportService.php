<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportDailyReportService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function show(User $authenticated, string $obligationId): array
    {
        return $this->write(function () use ($authenticated, $obligationId): array {
            $actor = $this->access->actor($authenticated);
            $this->access->requirePermission($actor, 'view_transport_operations');
            // Read access can remain available for historical read-only schools.
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->first();
            $row = $this->obligation($actor, $obligationId);

            return $this->response($row);
        });
    }

    public function submit(User $authenticated, string $obligationId, array $input): array
    {
        $data = $this->validate($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'summary' => ['required', 'string', 'min:10', 'max:4000'],
            'exception_note' => ['nullable', 'string', 'min:10', 'max:4000'],
        ]);

        return $this->write(function () use ($authenticated, $obligationId, $data): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'submit_transport_daily_reports');
            $row = $this->obligation($actor, $obligationId);
            $before = $this->response($row);
            abort_unless(hash_equals($before['version'], $data['expected_version']), 409, 'Report evidence changed. Refresh before submitting.');
            $responsible = DB::table('transport_dated_assignments as a')
                ->leftJoin('transport_trips as t', function ($join): void {
                    $join->on('t.school_id', '=', 'a.school_id')->on('t.assignment_id', '=', 'a.id');
                })
                ->where('a.school_id', $row->school_id)->where('a.schedule_id', $row->schedule_id)
                ->where('a.service_date', $row->service_date)
                ->where(function ($query): void {
                    $query->where('a.active', true)->orWhereNotNull('t.id');
                })
                ->where(function ($query) use ($actor): void {
                    $query->where('a.driver_id', $actor->getKey())->orWhere('a.attendant_id', $actor->getKey());
                })->exists();
            abort_unless($responsible, 403, 'A reporting permission also requires concrete service responsibility.');

            $latest = $before['submissions'] === [] ? null : $before['submissions'][array_key_last($before['submissions'])];
            abort_if($latest !== null && ($latest['review']['decision'] ?? null) !== 'changes_requested', 409, 'A new revision requires requested changes.');
            $id = (string) Str::uuid();
            DB::table('transport_daily_submissions')->insert([
                'id' => $id, 'school_id' => $row->school_id, 'obligation_id' => $row->id,
                'revision' => ($latest['revision'] ?? 0) + 1,
                'previous_id' => $latest['id'] ?? null,
                'summary' => trim($data['summary']),
                'exception_note' => isset($data['exception_note']) ? trim($data['exception_note']) : null,
                'snapshot' => json_encode($before['current_snapshot'], JSON_THROW_ON_ERROR),
                'submitted_by' => $actor->getKey(),
                'submitted_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ]);
            $this->audit->record($actor, 'submit', 'transport_daily_submissions', $id, [], [
                'obligation_id' => $row->id, 'revision' => ($latest['revision'] ?? 0) + 1,
                'totals' => $before['current_snapshot']['totals'],
            ]);

            return $this->response($row);
        });
    }

    public function review(User $authenticated, string $submissionId, array $input): array
    {
        $data = $this->validate($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'decision' => ['required', 'in:accepted,changes_requested'],
            'note' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        return $this->write(function () use ($authenticated, $submissionId, $data): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'review_transport_daily_reports');
            $submission = DB::table('transport_daily_submissions')
                ->where('school_id', $actor->school_id)->where('id', $submissionId)->first();
            abort_unless($submission !== null, 404);
            $row = $this->obligation($actor, (string) $submission->obligation_id);
            $before = $this->response($row);
            $latest = $before['submissions'][array_key_last($before['submissions'])];
            abort_unless($latest['id'] === $submissionId && $latest['review'] === null, 409, 'Submission already reviewed or superseded.');
            // Review token refers to immutable submission identity, not subsequent live events.
            abort_unless(hash_equals($latest['review_version'], $data['expected_version']), 409, 'Stale review token.');
            $contributors = $latest['snapshot']['contributors'];
            foreach ($before['submissions'] as $previous) {
                $contributors[] = $previous['submitted_by'];
                $contributors = [...$contributors, ...$previous['snapshot']['contributors']];
            }
            abort_if(in_array((string) $actor->getKey(), $contributors, true), 403, 'Operational contributors and submitters cannot review their own report.');
            $id = (string) Str::uuid();
            DB::table('transport_daily_reviews')->insert([
                'id' => $id, 'school_id' => $row->school_id,
                'submission_id' => $submissionId, 'decision' => $data['decision'],
                'note' => trim($data['note']), 'reviewed_by' => $actor->getKey(),
                'reviewed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ]);
            $this->audit->record($actor, 'review', 'transport_daily_reviews', $id, [], [
                'submission_id' => $submissionId, 'decision' => $data['decision'],
            ]);

            return $this->response($row);
        });
    }

    private function obligation(User $actor, string $id): object
    {
        $row = DB::table('transport_reporting_obligations')
            ->where('school_id', $actor->school_id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function response(object $row): array
    {
        $raw = DB::selectOne('SELECT public.transport_daily_report_snapshot(?::uuid, ?::uuid) AS snapshot', [$row->school_id, $row->id])->snapshot;
        $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $submissions = [];
        foreach (DB::table('transport_daily_submissions')->where('school_id', $row->school_id)
            ->where('obligation_id', $row->id)->orderBy('revision')->get() as $submission) {
            $review = DB::table('transport_daily_reviews')->where('school_id', $row->school_id)
                ->where('submission_id', $submission->id)->first();
            $submissions[] = [
                ...(array) $submission,
                'revision' => (int) $submission->revision,
                'snapshot' => json_decode($submission->snapshot, true, 512, JSON_THROW_ON_ERROR),
                'review' => $review === null ? null : (array) $review,
                'review_version' => hash('sha256', 'transport-review:'.$submission->id.':'.($review->id ?? 'pending')),
            ];
        }
        $last = $submissions === [] ? null : $submissions[array_key_last($submissions)];

        return [
            'obligation_id' => (string) $row->id,
            'state' => $last === null ? 'missing' : ($last['review']['decision'] ?? 'submitted'),
            'current_snapshot' => $snapshot,
            'submissions' => $submissions,
            'version' => hash('sha256', 'transport-daily:'.$row->id.':'.$raw.':'.($last['id'] ?? 'missing').':'.($last['review']['id'] ?? 'pending')),
        ];
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }

        return Validator::make($input, $rules)->validate();
    }

    private function write(callable $operation): array
    {
        try {
            return DB::transaction($operation, 3);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23505', '23P01'], true)) {
                abort(409, 'Submission or review conflicts with current evidence.');
            }
            if ($state === '23514') {
                abort(422, 'Reporting evidence, service window or exception account is invalid.');
            }
            throw $exception;
        }
    }
}
