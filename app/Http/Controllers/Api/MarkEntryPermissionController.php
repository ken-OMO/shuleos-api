<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\MarkEntryPermissionResource;
use App\Models\MarkEntryPermission;
use App\Services\Assessment\MarkEntryPermissionService;
use Illuminate\Http\Request;

class MarkEntryPermissionController extends BaseCrudController
{
    private const MODULE = 'Mark Entry Permissions';

    public function __construct(private readonly MarkEntryPermissionService $service) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'exam_id' => 'sometimes|uuid',
            'role_name' => 'sometimes|string|max:255',
            'active' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = MarkEntryPermission::with('exam')
            ->current()
            ->whereHas('exam', fn ($exam) => $exam
                ->where('school_id', $this->schoolId($request))
                ->where('is_deleted', false));

        foreach (['exam_id', 'role_name', 'active'] as $filter) {
            $query->when(
                array_key_exists($filter, $validated),
                fn ($builder) => $builder->where($filter, $validated[$filter])
            );
        }

        return $this->success(
            MarkEntryPermissionResource::collection(
                $query->orderByDesc('created_at')->paginate($validated['per_page'] ?? 20)
            ),
            'Mark entry permissions retrieved successfully.'
        );
    }

    public function show(Request $request, string $id)
    {
        $permission = $this->tenantQuery($request)->with('exam')->find($id);

        return $permission
            ? $this->success(new MarkEntryPermissionResource($permission), 'Mark entry permission retrieved successfully.')
            : $this->notFound('Mark entry permission not found.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'sometimes|uuid|exists:schools,id',
            'exam_id' => 'required|uuid|exists:exams,id',
            'role_name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after:opens_at',
        ]);

        $permission = $this->service->create(
            $validated,
            $this->schoolId($request, $validated)
        );

        $this->audit($request, self::MODULE, 'Create', $permission, null, $permission->toArray(), 'Created mark entry permission.');

        return $this->created(
            new MarkEntryPermissionResource($permission->load('exam')),
            'Mark entry permission created successfully.'
        );
    }

    public function update(Request $request, string $id)
    {
        $permission = $this->tenantQuery($request)->find($id);

        if (!$permission) {
            return $this->notFound('Mark entry permission not found.');
        }

        $validated = $request->validate([
            'active' => 'sometimes|boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after:opens_at',
        ]);

        $permission->update($validated);

        return $this->success(
            new MarkEntryPermissionResource($permission->refresh()->load('exam')),
            'Mark entry permission updated successfully.'
        );
    }

    public function destroy(Request $request, string $id)
    {
        $permission = $this->tenantQuery($request)->find($id);

        if (!$permission) {
            return $this->notFound('Mark entry permission not found.');
        }

        $permission->update([
            'active' => false,
            'is_deleted' => true,
            'deleted_at' => now(),
            'deleted_by' => auth()->id(),
        ]);

        return $this->success(null, 'Mark entry permission revoked successfully.');
    }

    private function tenantQuery(Request $request)
    {
        return MarkEntryPermission::current()->whereHas('exam', fn ($exam) => $exam
            ->where('school_id', $this->schoolId($request))
            ->where('is_deleted', false));
    }

    private function schoolId(Request $request, array $validated = []): string
    {
        $schoolId = $request->attributes->get('tenant_school_id')
            ?? $validated['school_id']
            ?? $request->input('school_id');

        abort_if(!$schoolId, 403, 'School context not found.');

        return (string) $schoolId;
    }
}
