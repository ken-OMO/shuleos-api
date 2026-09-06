<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use App\Models\LearnerParent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuardianController extends BaseCrudController
{
    private const MODULE = 'Guardians';

    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $guardians = Guardian::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            GuardianResource::collection($guardians),
            'Guardians retrieved successfully.'
        );
    }

    public function show(Request $request, string $id)
    {
        $guardian = $this->guardian(
            $this->schoolId($request),
            $id
        );

        return $this->success(
            new GuardianResource($guardian),
            'Guardian retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:50'],
        ]);

        $guardian = DB::transaction(function () use (
            $request,
            $validated,
            $schoolId
        ): Guardian {
            $guardian = Guardian::query()->create([
                'id' => (string) Str::uuid(),
                'school_id' => $schoolId,
                'user_id' => null,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'relationship' => $validated['relationship'] ?? null,
                'active' => true,
                'created_at' => now(),
            ]);

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Create',
                model: $guardian,
                oldValues: null,
                newValues: $guardian->toArray(),
                description: 'Created guardian.'
            );

            return $guardian;
        });

        return $this->created(
            new GuardianResource($guardian),
            'Guardian created successfully.'
        );
    }

    public function update(
        Request $request,
        string $id
    ) {
        $schoolId = $this->schoolId($request);

        $guardian = $this->guardian($schoolId, $id);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use (
            $request,
            $guardian,
            $validated
        ): void {
            $oldValues = $guardian->toArray();

            $guardian->fill($validated);
            $guardian->save();
            $guardian->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Update',
                model: $guardian,
                oldValues: $oldValues,
                newValues: $guardian->toArray(),
                description: 'Updated guardian.'
            );
        });

        $guardian->refresh();

        return $this->success(
            new GuardianResource($guardian),
            'Guardian updated successfully.'
        );
    }

    public function destroy(
        Request $request,
        string $id
    ) {
        $schoolId = $this->schoolId($request);
        $user = $request->user();

        $guardian = $this->guardian($schoolId, $id);

        DB::transaction(function () use (
            $request,
            $guardian,
            $user
        ): void {
            $oldValues = $guardian->toArray();

            LearnerParent::query()
                ->where('parent_id', $guardian->id)
                ->where('is_deleted', false)
                ->update([
                    'active' => false,
                    'portal_enabled' => false,
                    'is_primary_contact' => false,
                    'is_deleted' => true,
                    'deleted_at' => now(),
                    'deleted_by' => $user->id,
                    'updated_at' => now(),
                ]);

            $guardian->active = false;
            $guardian->is_deleted = true;
            $guardian->deleted_at = now();
            $guardian->deleted_by = $user->id;
            $guardian->save();
            $guardian->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Delete',
                model: $guardian,
                oldValues: $oldValues,
                newValues: $guardian->toArray(),
                description: 'Soft deleted guardian and revoked guardian links.'
            );
        });

        return $this->success(
            null,
            'Guardian deleted successfully.'
        );
    }

    private function guardian(
        string $schoolId,
        string $guardianId
    ): Guardian {
        return Guardian::query()
            ->withoutGlobalScopes()
            ->where('id', $guardianId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function schoolId(Request $request): string
    {
        $schoolId = trim(
            (string) ($request->user()?->school_id ?? '')
        );

        if ($schoolId === '') {
            abort(403, 'School context not found.');
        }

        return $schoolId;
    }
}
