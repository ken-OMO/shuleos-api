<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoardingReportingPolicyService
{
    public function __construct(
        private BoardingReportingAccessService $access,
        private BoardingReportingAuditService $audit,
    ) {}

    public function read(User $user): array
    {
        $actor = $this->access->actor($user);
        $this->access->requirePermission($actor, 'configure_boarding_reporting');

        return DB::table('boarding_reporting_policies')
            ->where('school_id', $actor->school_id)
            ->orderByDesc('revision')->limit(20)
            ->get([
                'id', 'revision', 'enabled', 'effective_from', 'timezone',
                'deadline_time', 'deadline_day_offset', 'grace_minutes',
                'weekday_mask', 'created_at',
            ])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'revision' => (int) $row->revision,
                'enabled' => (bool) $row->enabled,
                'effective_from' => $row->effective_from,
                'timezone' => $row->timezone,
                'deadline_time' => $row->deadline_time,
                'deadline_day_offset' => (int) $row->deadline_day_offset,
                'grace_minutes' => (int) $row->grace_minutes,
                'weekday_mask' => (int) $row->weekday_mask,
                'created_at' => $row->created_at,
            ])->all();
    }

    public function configure(User $user, int $expectedRevision, array $data): array
    {
        return DB::transaction(function () use ($user, $expectedRevision, $data): array {
            $actor = $this->writer($user);
            $validated = validator($data, [
                'enabled' => ['required', 'boolean'],
                'effective_from' => ['required', 'date_format:Y-m-d'],
                'deadline_time' => ['required', 'date_format:H:i'],
                'deadline_day_offset' => ['required', 'integer', 'between:0,1'],
                'grace_minutes' => ['required', 'integer', 'between:0,1440'],
                'weekday_mask' => ['required', 'integer', 'between:1,127'],
                'school_id' => ['prohibited'],
                'timezone' => ['prohibited'],
                'created_by' => ['prohibited'],
                'revision' => ['prohibited'],
            ])->validate();

            $timezone = (string) $actor->school->timezone;
            validator(['timezone' => $timezone], [
                'timezone' => ['required', 'timezone'],
            ])->validate();

            $latest = DB::table('boarding_reporting_policies')
                ->where('school_id', $actor->school_id)
                ->orderByDesc('revision')->first();
            abort_unless(
                $expectedRevision >= 0
                && $expectedRevision === (int) ($latest->revision ?? 0),
                409
            );

            $today = CarbonImmutable::now($timezone)->toDateString();
            $effective = $validated['effective_from'];

            // Initial configuration may start today. Revisions start after today
            // and after the latest scheduled policy; no existing policy is edited.
            if ($effective < $today
                || ($latest && ($effective <= $today
                    || $effective <= $latest->effective_from))) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Use a prospective date after the latest policy; existing policy dates cannot be replaced.',
                ]);
            }

            if (DB::table('boarding_daily_obligations')
                ->where('school_id', $actor->school_id)
                ->where('reporting_date', '>=', $effective)->exists()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Existing obligations already preserve policy evidence for this date range.',
                ]);
            }

            $id = (string) Str::uuid();
            DB::table('boarding_reporting_policies')->insert([
                'id' => $id,
                'school_id' => $actor->school_id,
                'revision' => $expectedRevision + 1,
                'enabled' => (bool) $validated['enabled'],
                'effective_from' => $effective,
                'timezone' => $timezone,
                'deadline_time' => $validated['deadline_time'],
                'deadline_day_offset' => (int) $validated['deadline_day_offset'],
                'grace_minutes' => (int) $validated['grace_minutes'],
                'weekday_mask' => (int) $validated['weekday_mask'],
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                $actor, 'reporting_policy_configured',
                'boarding_reporting_policies', $id,
                ['previous_revision' => $expectedRevision],
                [
                    'revision' => $expectedRevision + 1,
                    'effective_from' => $effective,
                    'enabled' => (bool) $validated['enabled'],
                    'timezone' => $timezone,
                    'deadline_time' => $validated['deadline_time'],
                    'deadline_day_offset' => (int) $validated['deadline_day_offset'],
                    'grace_minutes' => (int) $validated['grace_minutes'],
                    'weekday_mask' => (int) $validated['weekday_mask'],
                ]
            );

            return $this->read($actor)[0];
        });
    }

    public function materializeToday(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $actor = $this->writer($user);
            $timezone = (string) $actor->school->timezone;
            validator(['timezone' => $timezone], [
                'timezone' => ['required', 'timezone'],
            ])->validate();

            $today = CarbonImmutable::now($timezone);
            $date = $today->toDateString();
            $policy = DB::table('boarding_reporting_policies')
                ->where('school_id', $actor->school_id)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')->orderByDesc('revision')->first();

            $result = [
                'reporting_date' => $date,
                'configured' => $policy !== null,
                'created' => 0,
                'obligation_ids' => [],
            ];

            if (! $policy || ! $policy->enabled
                || (((int) $policy->weekday_mask & (1 << ($today->dayOfWeekIso - 1))) === 0)) {
                return $result;
            }

            // Do not infer past facility availability from today's active state.
            // This method intentionally supports today's date only.
            $hostels = DB::table('hostels')
                ->where('school_id', $actor->school_id)
                ->where('active', true)->where('is_deleted', false)
                ->orderBy('id')->get(['id']);

            foreach ($hostels as $hostel) {
                if (DB::table('boarding_daily_obligations')
                    ->where('school_id', $actor->school_id)
                    ->where('hostel_id', $hostel->id)
                    ->where('reporting_date', $date)->exists()) {
                    continue;
                }

                $id = (string) Str::uuid();

                // No assignment filter: unassigned facilities still owe reports.
                // PostgreSQL derives and freezes timezone and the effective deadline.
                DB::table('boarding_daily_obligations')->insert([
                    'id' => $id,
                    'school_id' => $actor->school_id,
                    'hostel_id' => $hostel->id,
                    'policy_id' => $policy->id,
                    'reporting_date' => $date,
                    'created_by' => $actor->id,
                ]);

                $this->audit->record(
                    $actor, 'reporting_obligation_materialized',
                    'boarding_daily_obligations', $id, [],
                    [
                        'hostel_id' => $hostel->id,
                        'policy_id' => $policy->id,
                        'reporting_date' => $date,
                    ]
                );

                $result['obligation_ids'][] = $id;
                $result['created']++;
            }

            return $result;
        });
    }

    private function writer(User $user): User
    {
        // Locks the school before policy/obligation work and checks write gates.
        $actor = $this->access->actor($user, true);
        $this->access->requirePermission($actor, 'configure_boarding_reporting');

        return $actor;
    }
}
