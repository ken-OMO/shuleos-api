<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuardianController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Guardians';

    /**
     * Relationships loaded with guardian responses.
     */
    private const RELATIONS = [

        'school',

        'user',

        'learners',

    ];

    /**
     * Display a listing of guardians.
     */
    public function index()
    {
        $guardians = Guardian::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('created_at')
        ->get();

        return $this->success(

            GuardianResource::collection(

                $guardians

            ),

            'Guardians retrieved successfully.'

        );
    }

    /**
     * Display the specified guardian.
     */
    public function show(string $id)
    {
        $guardian = Guardian::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($guardian)) {

            return $this->notFound(

                'Guardian not found.'

            );

        }

        return $this->success(

            new GuardianResource(

                $guardian

            ),

            'Guardian retrieved successfully.'

        );
    }
        /**
     * Store a newly created guardian.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'user_id' => 'nullable|exists:users,id',

            'first_name' => 'required|string|max:100',

            'last_name' => 'required|string|max:100',

            'phone' => 'required|string|max:20',

            'email' => 'nullable|email|max:255',

            'relationship' => 'required|string|max:50',

        ]);

        $this->beginTransaction();

        try {

            $guardian = Guardian::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'user_id' => $validated['user_id'] ?? null,

                'first_name' => $validated['first_name'],

                'last_name' => $validated['last_name'],

                'phone' => $validated['phone'],

                'email' => $validated['email'] ?? null,

                'relationship' => $validated['relationship'],

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

            $this->commit();

            $this->loadRelations(

                $guardian,

                self::RELATIONS

            );

            return $this->created(

                new GuardianResource(

                    $guardian

                ),

                'Guardian created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create guardian.',

                [

                    'school_id' => $request->school_id,

                    'phone' => $request->phone,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create guardian.'

            );

        }
    }
        /**
     * Update the specified guardian.
     */
    public function update(Request $request, string $id)
    {
        $guardian = Guardian::find($id);

        if ($this->modelNotFound($guardian)) {

            return $this->notFound(

                'Guardian not found.'

            );

        }

        if ($this->isDeleted($guardian)) {

            return $this->badRequest(

                'Guardian has been deleted.'

            );

        }

        $validated = $request->validate([

            'user_id' => 'nullable|exists:users,id',

            'first_name' => 'sometimes|string|max:100',

            'last_name' => 'sometimes|string|max:100',

            'phone' => 'sometimes|string|max:20',

            'email' => 'nullable|email|max:255',

            'relationship' => 'sometimes|string|max:50',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $guardian->toArray();

            $guardian->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $guardian,

                oldValues: $oldValues,

                newValues: $guardian->fresh()->toArray(),

                description: 'Updated guardian.'

            );

            $this->commit();

            $this->loadRelations(

                $guardian,

                self::RELATIONS

            );

            return $this->success(

                new GuardianResource(

                    $guardian

                ),

                'Guardian updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update guardian.',

                [

                    'guardian_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update guardian.'

            );

        }
    }
        /**
     * Soft delete the specified guardian.
     */
    public function destroy(Request $request, string $id)
    {
        $guardian = Guardian::find($id);

        if ($this->modelNotFound($guardian)) {

            return $this->notFound(

                'Guardian not found.'

            );

        }

        if ($this->isDeleted($guardian)) {

            return $this->badRequest(

                'Guardian has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $guardian->toArray();

            $guardian->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $guardian,

                oldValues: $oldValues,

                newValues: $guardian->fresh()->toArray(),

                description: 'Soft deleted guardian.'

            );

            $this->commit();

            return $this->success(

                null,

                'Guardian deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete guardian.',

                [

                    'guardian_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete guardian.'

            );

        }
    }
}
