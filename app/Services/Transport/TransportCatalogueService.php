<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransportCatalogueService
{
    private const RESOURCES = [
        'vehicles' => ['transport_vehicles', ['id', 'registration_number', 'ownership_type', 'learner_capacity', 'active']],
        'routes' => ['transport_routes', ['id', 'route_code', 'route_name', 'active']],
        'stops' => ['transport_route_stops', ['id', 'route_id', 'direction', 'stop_name', 'stop_order', 'active']],
        'schedules' => ['transport_service_schedules', ['id', 'route_id', 'service_code', 'direction', 'timezone', 'effective_from', 'effective_to', 'active']],
        'assignments' => ['transport_dated_assignments', ['id', 'schedule_id', 'service_date', 'run_number', 'vehicle_id', 'driver_id', 'attendant_id', 'active']],
        'reservations' => ['transport_learner_reservations', ['id', 'assignment_id', 'learner_id', 'stop_id', 'active']],
        'enrolments' => ['transport_enrolment_episodes', ['id', 'learner_id', 'schedule_id', 'stop_id', 'effective_from', 'effective_to', 'weekday_mask']],
        'trips' => ['transport_trips', ['id', 'assignment_id', 'service_date', 'direction', 'roster_count', 'captured_at']],
        'authorizations' => ['transport_handover_authorizations', ['id', 'assignment_id', 'kind', 'valid_from', 'valid_to']],
    ];

    public function __construct(private TransportAccessService $access) {}

    public function listing(User $authenticated, string $resource, array $input): array
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        foreach (array_diff(array_keys($input), ['limit', 'offset']) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
        $data = Validator::make($input, [
            'limit' => ['sometimes', 'required', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'required', 'integer', 'between:0,100000'],
        ])->validate();

        return DB::transaction(function () use ($authenticated, $resource, $data): array {
            $actor = $this->access->actor($authenticated);
            $this->access->requirePermission($actor, 'view_transport_operations');
            DB::table('schools')->where('id', $actor->school_id)->sharedLock()->first();
            [$table, $columns] = self::RESOURCES[$resource];
            $query = DB::table($table)->where('school_id', $actor->school_id);
            $total = (clone $query)->count();
            $limit = (int) ($data['limit'] ?? 50);
            $offset = (int) ($data['offset'] ?? 0);

            return [
                'data' => $query->orderBy('id')->limit($limit)->offset($offset)->get($columns)->map(fn ($row) => (array) $row)->all(),
                'meta' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
            ];
        });
    }
}
