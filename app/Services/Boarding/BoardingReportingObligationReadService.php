<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardingReportingObligationReadService
{
    public function __construct(
        private BoardingReportingAccessService $access,
        private AuthContextService $auth,
    ) {}

    public function read(User $user, array $filters = []): array
    {
        $actor = $this->access->actor($user);
        $schoolWide = $this->access->schoolWideReader($actor);

        if (! $schoolWide) {
            $this->access->requirePermission($actor, 'record_boarding_operations');
        }

        $filters = validator($filters, [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'hostel_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'school_id' => ['prohibited'],
        ])->validate();

        $today = CarbonImmutable::now(
            $actor->school->timezone ?: config('app.timezone')
        )->toDateString();
        $from = $filters['from'] ?? $today;
        $to = $filters['to'] ?? $from;

        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(
            CarbonImmutable::parse($to)
        ) > 30) {
            throw ValidationException::withMessages([
                'to' => 'Select an ordered date range containing at most 31 days.',
            ]);
        }

        if (isset($filters['hostel_id'])) {
            abort_unless(DB::table('hostels')
                ->where('school_id', $actor->school_id)
                ->where('id', $filters['hostel_id'])->exists(), 404);
        }

        $latest = DB::table('boarding_daily_report_revisions')
            ->where('school_id', $actor->school_id)
            ->selectRaw('DISTINCT ON (school_id, report_id) school_id, report_id, id, revision_number')
            ->orderBy('school_id')->orderBy('report_id')
            ->orderByDesc('revision_number');

        $firstSubmission = DB::table('boarding_daily_report_submissions as evidence')
            ->join('boarding_daily_report_revisions as submitted_revision', function ($join): void {
                $join->on('submitted_revision.school_id', '=', 'evidence.school_id')
                    ->on('submitted_revision.id', '=', 'evidence.revision_id');
            })
            ->where('evidence.school_id', $actor->school_id)
            ->selectRaw('evidence.school_id, submitted_revision.report_id, MIN(evidence.submitted_at) AS first_submitted_at')
            ->groupBy('evidence.school_id', 'submitted_revision.report_id');

        $query = DB::table('boarding_daily_obligations as obligation')
            ->join('hostels as hostel', function ($join): void {
                $join->on('hostel.school_id', '=', 'obligation.school_id')
                    ->on('hostel.id', '=', 'obligation.hostel_id');
            })
            ->leftJoin('boarding_daily_reports as report', function ($join): void {
                $join->on('report.school_id', '=', 'obligation.school_id')
                    ->on('report.obligation_id', '=', 'obligation.id');
            })
            ->leftJoinSub($latest, 'revision', function ($join): void {
                $join->on('revision.school_id', '=', 'report.school_id')
                    ->on('revision.report_id', '=', 'report.id');
            })
            ->leftJoin('boarding_daily_report_submissions as submission', function ($join): void {
                $join->on('submission.school_id', '=', 'revision.school_id')
                    ->on('submission.revision_id', '=', 'revision.id');
            })
            ->leftJoin('boarding_daily_report_reviews as review', function ($join): void {
                $join->on('review.school_id', '=', 'revision.school_id')
                    ->on('review.revision_id', '=', 'revision.id');
            })
            ->leftJoinSub($firstSubmission, 'first_submission', function ($join): void {
                $join->on('first_submission.school_id', '=', 'report.school_id')
                    ->on('first_submission.report_id', '=', 'report.id');
            })
            ->where('obligation.school_id', $actor->school_id)
            ->whereBetween('obligation.reporting_date', [$from, $to]);

        if (isset($filters['hostel_id'])) {
            $query->where('obligation.hostel_id', $filters['hostel_id']);
        }

        if (! $schoolWide) {
            $query->whereExists(function ($assignment) use ($actor): void {
                $assignment->selectRaw('1')
                    ->from('hostel_staff_assignments as assignment')
                    ->whereColumn('assignment.school_id', 'obligation.school_id')
                    ->whereColumn('assignment.hostel_id', 'obligation.hostel_id')
                    ->where('assignment.user_id', $actor->id)
                    ->whereColumn('assignment.effective_from', '<=', 'obligation.reporting_date')
                    ->where(fn ($end) => $end->whereNull('assignment.effective_to')
                        ->orWhereColumn('assignment.effective_to', '>=', 'obligation.reporting_date'));
            });
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $total = (clone $query)->count('obligation.id');
        $now = CarbonImmutable::now();

        $rows = $query
            ->orderByDesc('obligation.reporting_date')->orderBy('obligation.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get([
                'obligation.id', 'obligation.hostel_id', 'obligation.reporting_date',
                'obligation.timezone', 'obligation.due_at', 'hostel.hostel_name',
                'report.id as report_id', 'revision.id as revision_id',
                'revision.revision_number', 'submission.id as submission_id',
                'review.decision', 'first_submission.first_submitted_at',
            ]);

        return [
            'data' => $rows->map(function ($row) use ($actor, $now): array {
                $deadline = CarbonImmutable::parse($row->due_at);
                $first = $row->first_submitted_at;

                return [
                    'id' => (string) $row->id,
                    'hostel_id' => (string) $row->hostel_id,
                    'hostel_name' => $row->hostel_name,
                    'reporting_date' => $row->reporting_date,
                    'timezone' => $row->timezone,
                    'deadline_at' => $deadline->toIso8601String(),
                    'report_id' => $row->report_id,
                    'revision_id' => $row->revision_id,
                    'revision_number' => $row->revision_number === null
                        ? null : (int) $row->revision_number,
                    'state' => $row->decision ?? ($row->submission_id
                        ? 'submitted' : ($row->revision_id ? 'draft' : 'missing')),
                    'first_submitted_at' => $first,
                    'late' => $first !== null
                        && CarbonImmutable::parse($first)->greaterThan($deadline),
                    'overdue' => $first === null && $now->greaterThan($deadline),
                    'unassigned' => ! $this->hasEligibleReporter(
                        (string) $actor->school_id,
                        (string) $row->hostel_id,
                        $row->reporting_date
                    ),
                ];
            })->all(),
            'meta' => [
                'page' => $page, 'per_page' => $perPage,
                'total' => $total, 'from' => $from, 'to' => $to,
            ],
        ];
    }

    private function hasEligibleReporter(string $schoolId, string $hostelId, string $date): bool
    {
        $users = User::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('active', true)->where('is_deleted', false)
            ->whereNull('suspended_at')
            ->whereExists(function ($assignment) use ($schoolId, $hostelId, $date): void {
                $assignment->selectRaw('1')->from('hostel_staff_assignments as assignment')
                    ->where('assignment.school_id', $schoolId)
                    ->where('assignment.hostel_id', $hostelId)
                    ->whereColumn('assignment.user_id', 'users.id')
                    ->where('assignment.effective_from', '<=', $date)
                    ->where(fn ($end) => $end->whereNull('assignment.effective_to')
                        ->orWhere('assignment.effective_to', '>=', $date));
            })->get();

        foreach ($users as $user) {
            try {
                if ($this->auth->hasPermission($user, 'record_boarding_operations')) {
                    return true;
                }
            } catch (AuthenticationException|AuthorizationException) {
                // Current access restrictions make this person ineligible to report.
            }
        }

        return false;
    }
}
