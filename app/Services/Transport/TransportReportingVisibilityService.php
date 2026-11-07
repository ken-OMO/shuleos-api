<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransportReportingVisibilityService
{
    public function __construct(
        private TransportAccessService $access,
        private AuthContextService $auth,
    ) {}

    public function listing(User $authenticated, array $input = []): array
    {
        $rules = [
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'schedule_id' => ['sometimes', 'required', 'uuid'],
            'report_state' => ['sometimes', 'required', 'in:missing,submitted,changes_requested,accepted'],
            'responsibility' => ['sometimes', 'required', 'in:assigned,unassigned'],
            'materialization' => ['sometimes', 'required', 'in:materialized,pending'],
            'overdue' => ['sometimes', 'required', 'boolean'],
            'limit' => ['sometimes', 'required', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'required', 'integer', 'between:0,100000'],
        ];
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
        $data = Validator::make($input, $rules)->validate();

        return DB::transaction(fn (): array => $this->readListing($authenticated, $data));
    }

    private function readListing(User $authenticated, array $data): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');
        // Serialize with the existing school-first Transport write locks.
        DB::table('schools')->where('id', $actor->school_id)->sharedLock()->first();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($actor->school->timezone ?: config('app.timezone'))->toDateString();
        $from = $data['date_from'] ?? $today;
        $to = $data['date_to'] ?? $from;
        if ($from > $to || CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) > 30) {
            throw ValidationException::withMessages(['date_to' => ['Choose at most 31 consecutive dates in ascending order.']]);
        }
        if (isset($data['schedule_id'])) {
            abort_unless(DB::table('transport_service_schedules')->where('school_id', $actor->school_id)
                ->where('id', $data['schedule_id'])->exists(), 404);
        }

        // Current eligible crew only. Historical permissions are not reconstructed.
        $candidateIds = DB::table('transport_dated_assignments as a')
            ->leftJoin('transport_trips as t', function ($join): void {
                $join->on('t.school_id', '=', 'a.school_id')->on('t.assignment_id', '=', 'a.id');
            })
            ->where('a.school_id', $actor->school_id)->whereBetween('a.service_date', [$from, $to])
            ->when(isset($data['schedule_id']), fn ($query) => $query->where('a.schedule_id', $data['schedule_id']))
            ->where(function ($query): void {
                $query->where('a.active', true)->orWhereNotNull('t.id');
            })
            ->get(['a.driver_id', 'a.attendant_id'])
            ->flatMap(fn ($row) => [$row->driver_id, $row->attendant_id])->filter()->unique()->values()->all();
        $eligible = [];
        foreach (User::withoutGlobalScopes()->where('school_id', $actor->school_id)->whereIn('id', $candidateIds)->get() as $user) {
            if (! $user->active || $user->is_deleted || $user->suspended_at || $user->account_locked_until?->isFuture()) {
                continue;
            }
            $user->setRelation('school', $actor->school);
            // Includes supplementary roles through the existing authorization service.
            if ($this->auth->hasPermission($user, 'submit_transport_daily_reports')) {
                $eligible[(string) $user->getKey()] = [
                    'id' => (string) $user->getKey(), 'first_name' => $user->first_name, 'last_name' => $user->last_name,
                ];
            }
        }
        $parameters = [
            'school_id' => (string) $actor->school_id, 'date_from' => $from, 'date_to' => $to,
            'as_of' => $now->toIso8601String(), 'eligible' => array_keys($eligible),
            'schedule_id' => $data['schedule_id'] ?? null, 'report_state' => $data['report_state'] ?? null,
            'responsibility' => $data['responsibility'] ?? null, 'materialization' => $data['materialization'] ?? null,
            'overdue' => isset($data['overdue']) ? (bool) $data['overdue'] : null,
            'page_limit' => (int) ($data['limit'] ?? 50), 'page_offset' => (int) ($data['offset'] ?? 0),
        ];
        $raw = DB::selectOne(<<<'SQL'
WITH parameters AS (
    SELECT * FROM jsonb_to_record(?::jsonb) AS x(
        school_id uuid, date_from date, date_to date, as_of timestamptz, eligible jsonb,
        schedule_id uuid, report_state text, responsibility text, materialization text,
        overdue boolean, page_limit integer, page_offset integer
    )
), eligible AS (
    SELECT value::uuid AS id FROM parameters, jsonb_array_elements_text(parameters.eligible)
), expected AS (
    SELECT p.school_id,p.id AS policy_id,p.schedule_id,d.day::date AS service_date
    FROM parameters x JOIN transport_reporting_policies p ON p.school_id=x.school_id
    CROSS JOIN LATERAL generate_series(
        GREATEST(p.effective_from,x.date_from)::timestamp,
        LEAST(p.effective_to,x.date_to,(x.as_of AT TIME ZONE p.timezone)::date)::timestamp,
        interval '1 day'
    ) d(day)
    WHERE (x.schedule_id IS NULL OR p.schedule_id=x.schedule_id)
      AND (p.weekday_mask & (1 << (extract(isodow FROM d.day)::integer-1))) <> 0
), scopes AS (
    SELECT * FROM expected
    UNION
    SELECT o.school_id,o.policy_id,o.schedule_id,o.service_date
    FROM parameters x JOIN transport_reporting_obligations o ON o.school_id=x.school_id
    WHERE o.service_date BETWEEN x.date_from AND x.date_to
      AND o.service_date <= (x.as_of AT TIME ZONE o.timezone)::date
      AND (x.schedule_id IS NULL OR o.schedule_id=x.schedule_id)
), base AS (
    SELECT d.*, o.id AS obligation_id, p.timezone,
        s.service_code,s.direction,s.active AS schedule_active,
        COALESCE(o.deadline_at, ((d.service_date+p.deadline_day_offset)+p.deadline_time) AT TIME ZONE p.timezone) AS deadline_at,
        COALESCE(o.grace_until, (((d.service_date+p.deadline_day_offset)+p.deadline_time) AT TIME ZONE p.timezone)+make_interval(mins=>p.grace_minutes)) AS grace_until,
        o.id IS NOT NULL AS is_materialized,
        CASE WHEN o.id IS NOT NULL THEN 'materialized_obligation' ELSE 'policy_preview' END AS deadline_source,
        CASE WHEN o.id IS NOT NULL THEN NULL
             WHEN NOT s.active THEN 'schedule_inactive'
             WHEN ((((d.service_date+p.deadline_day_offset)+p.deadline_time) AT TIME ZONE p.timezone) AT TIME ZONE p.timezone) <> ((d.service_date+p.deadline_day_offset)+p.deadline_time) THEN 'invalid_local_deadline'
             WHEN d.service_date < (x.as_of AT TIME ZONE p.timezone)::date THEN 'historical_materialization_not_supported'
             WHEN EXISTS (SELECT 1 FROM transport_dated_assignments a WHERE a.school_id=d.school_id AND a.schedule_id=d.schedule_id AND a.service_date=d.service_date AND a.completes_at > (((d.service_date+p.deadline_day_offset)+p.deadline_time) AT TIME ZONE p.timezone)) THEN 'deadline_precedes_assigned_completion'
             ELSE NULL END AS materialization_blocker,
        CASE WHEN last_submission.id IS NULL THEN 'missing'
             ELSE COALESCE(review.decision,'submitted') END AS report_state,
        last_submission.id AS latest_submission_id,last_submission.revision AS latest_revision,
        last_submission.submitted_at AS latest_submitted_at,
        first_submission.submitted_at AS first_submitted_at,
        first_submission.late AS first_submission_late,
        first_submission.overdue AS first_submission_after_grace,
        COALESCE(crew.ids,'[]'::jsonb) AS reporter_ids,
        CASE WHEN jsonb_array_length(COALESCE(crew.ids,'[]'::jsonb))=0 THEN 'unassigned' ELSE 'assigned' END AS responsibility_state
    FROM scopes d CROSS JOIN parameters x
    JOIN transport_reporting_policies p ON p.school_id=d.school_id AND p.id=d.policy_id
    JOIN transport_service_schedules s ON s.school_id=d.school_id AND s.id=d.schedule_id
    LEFT JOIN transport_reporting_obligations o ON o.school_id=d.school_id AND o.schedule_id=d.schedule_id AND o.service_date=d.service_date
    LEFT JOIN LATERAL (SELECT a.* FROM transport_daily_submissions a WHERE a.school_id=d.school_id AND a.obligation_id=o.id ORDER BY revision DESC LIMIT 1) last_submission ON true
    LEFT JOIN LATERAL (SELECT a.submitted_at,a.late,a.overdue FROM transport_daily_submissions a WHERE a.school_id=d.school_id AND a.obligation_id=o.id ORDER BY revision LIMIT 1) first_submission ON true
    LEFT JOIN transport_daily_reviews review ON review.school_id=d.school_id AND review.submission_id=last_submission.id
    LEFT JOIN LATERAL (
        SELECT jsonb_agg(DISTINCT person.id ORDER BY person.id) AS ids
        FROM transport_dated_assignments a
        LEFT JOIN transport_trips t ON t.school_id=a.school_id AND t.assignment_id=a.id
        CROSS JOIN LATERAL unnest(ARRAY[a.driver_id,a.attendant_id]) person(id)
        JOIN eligible e ON e.id=person.id
        WHERE a.school_id=d.school_id AND a.schedule_id=d.schedule_id AND a.service_date=d.service_date
          AND (a.active OR t.id IS NOT NULL)
    ) crew ON true
), classified AS (
    SELECT b.*,
        CASE WHEN b.is_materialized THEN 'materialized' ELSE 'pending' END AS materialization_state,
        CASE WHEN b.materialization_blocker='invalid_local_deadline' THEN NULL ELSE x.as_of>b.deadline_at END AS deadline_passed,
        CASE WHEN b.materialization_blocker='invalid_local_deadline' THEN NULL ELSE x.as_of>b.grace_until END AS grace_expired,
        CASE WHEN b.materialization_blocker='invalid_local_deadline' THEN NULL ELSE b.report_state IN ('missing','changes_requested') AND x.as_of>b.grace_until END AS overdue
    FROM base b CROSS JOIN parameters x
), filtered AS (
    SELECT c.* FROM classified c CROSS JOIN parameters x
    WHERE (x.report_state IS NULL OR c.report_state=x.report_state)
      AND (x.responsibility IS NULL OR c.responsibility_state=x.responsibility)
      AND (x.materialization IS NULL OR c.materialization_state=x.materialization)
      AND (x.overdue IS NULL OR c.overdue=x.overdue)
), page AS (
    SELECT * FROM filtered ORDER BY service_date DESC,schedule_id,policy_id
    LIMIT (SELECT page_limit FROM parameters) OFFSET (SELECT page_offset FROM parameters)
)
SELECT jsonb_build_object(
    'total',(SELECT count(*) FROM filtered),
    'summary',(SELECT jsonb_build_object(
        'missing',count(*) FILTER (WHERE report_state='missing'),
        'submitted',count(*) FILTER (WHERE report_state='submitted'),
        'changes_requested',count(*) FILTER (WHERE report_state='changes_requested'),
        'accepted',count(*) FILTER (WHERE report_state='accepted'),
        'overdue',count(*) FILTER (WHERE overdue),
        'unassigned',count(*) FILTER (WHERE responsibility_state='unassigned'),
        'not_materialized',count(*) FILTER (WHERE NOT is_materialized)
    ) FROM filtered),
    'rows',COALESCE((SELECT jsonb_agg(to_jsonb(page) ORDER BY service_date DESC,schedule_id,policy_id) FROM page),'[]'::jsonb)
) AS result
SQL, [json_encode($parameters, JSON_THROW_ON_ERROR)])->result;
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $row['responsible_staff'] = array_values(array_map(fn ($id) => $eligible[$id], $row['reporter_ids']));
            unset($row['school_id'], $row['reporter_ids'], $row['is_materialized']);
            $rows[] = $row;
        }

        return [
            'data' => $rows,
            'meta' => [
                'date_from' => $from, 'date_to' => $to, 'as_of' => $parameters['as_of'],
                'limit' => $parameters['page_limit'], 'offset' => $parameters['page_offset'],
                'total' => $result['total'], 'responsibility_basis' => 'current_eligible_assigned_staff',
                'summary_scope' => 'filtered_result',
            ],
            'summary' => $result['summary'],
        ];
    }
}
