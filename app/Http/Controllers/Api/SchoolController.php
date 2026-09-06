<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\SchoolResource;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SchoolController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Schools';

    /**
     * Relationships loaded with school responses.
     */
    private const RELATIONS = [];

    /**
     * Display a listing of schools.
     */
    public function index()
    {
        $schools = School::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->orderBy('school_name')
            ->get();

        return $this->success(

            SchoolResource::collection(

                $schools

            ),

            'Schools retrieved successfully.'

        );
    }

    /**
     * Display the specified school.
     */
    public function show(string $id)
    {
        $school = School::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->find($id);

        if (! $school) {

            return $this->notFound(

                'School not found.'

            );

        }

        return $this->success(

            new SchoolResource(

                $school

            ),

            'School retrieved successfully.'

        );
    }

    /**
     * Store a newly created school.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_name' => 'required|string|max:255',

            'school_code' => 'required|string|max:50|unique:schools,school_code',

            'email' => 'nullable|email|max:255',

            'phone' => 'nullable|string|max:50',

            'county' => 'nullable|string|max:100',

            'sub_county' => 'nullable|string|max:100',

            'postal_address' => 'nullable|string|max:255',

            'physical_address' => 'nullable|string|max:255',

            'logo_url' => 'nullable|string|max:255',

            'school_type' => 'nullable|string|max:100',

            'ownership' => 'nullable|string|max:100',

            'registration_number' => 'nullable|string|max:100',

            'kra_pin' => 'nullable|string|max:50',

            'website' => 'nullable|url|max:255',

        ]);

        $this->beginTransaction();

        try {

            $school = School::create([

                'id' => (string) Str::uuid(),

                'school_name' => $validated['school_name'],

                'school_code' => $validated['school_code'],

                'email' => $validated['email'] ?? null,

                'phone' => $validated['phone'] ?? null,

                'county' => $validated['county'] ?? null,

                'sub_county' => $validated['sub_county'] ?? null,

                'postal_address' => $validated['postal_address'] ?? null,

                'physical_address' => $validated['physical_address'] ?? null,

                'logo_url' => $validated['logo_url'] ?? null,

                'school_type' => $validated['school_type'] ?? null,

                'ownership' => $validated['ownership'] ?? null,

                'registration_number' => $validated['registration_number'] ?? null,

                'kra_pin' => $validated['kra_pin'] ?? null,

                'website' => $validated['website'] ?? null,

                'active' => true,

                'is_deleted' => false,

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $school,

                oldValues: null,

                newValues: $school->toArray(),

                description: 'Created school.'

            );

            $this->commit();

            $this->loadRelations(

                $school,

                self::RELATIONS

            );

            return $this->created(

                new SchoolResource(

                    $school

                ),

                'School created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create school.',

                [

                    'school_code' => $request->school_code,

                    'school_name' => $request->school_name,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create school.'

            );

        }
    }

    /**
     * Update the specified school.
     */
    public function update(Request $request, string $id)
    {
        $school = School::find($id);

        if (! $school) {

            return $this->notFound(

                'School not found.'

            );

        }

        if ($this->isDeleted($school)) {

            return $this->badRequest(

                'School has been deleted.'

            );

        }

        $validated = $request->validate([

            'school_name' => 'sometimes|string|max:255',

            'school_code' => 'sometimes|string|max:50|unique:schools,school_code,'.$id.',id',

            'email' => 'nullable|email|max:255',

            'phone' => 'nullable|string|max:50',

            'county' => 'nullable|string|max:100',

            'sub_county' => 'nullable|string|max:100',

            'postal_address' => 'nullable|string|max:255',

            'physical_address' => 'nullable|string|max:255',

            'logo_url' => 'nullable|string|max:255',

            'school_type' => 'nullable|string|max:100',

            'ownership' => 'nullable|string|max:100',

            'registration_number' => 'nullable|string|max:100',

            'kra_pin' => 'nullable|string|max:50',

            'website' => 'nullable|url|max:255',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $school->toArray();

            $school->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $school,

                oldValues: $oldValues,

                newValues: $school->fresh()->toArray(),

                description: 'Updated school.'

            );

            $this->commit();

            $this->loadRelations(

                $school,

                self::RELATIONS

            );

            return $this->success(

                new SchoolResource(

                    $school

                ),

                'School updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update school.',

                [

                    'school_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update school.'

            );

        }
    }

    /**
     * Soft delete the specified school.
     */
    public function destroy(Request $request, string $id)
    {
        $school = School::find($id);

        if (! $school) {

            return $this->notFound(

                'School not found.'

            );

        }

        if ($this->isDeleted($school)) {

            return $this->badRequest(

                'School has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $school->toArray();

            $school->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $school,

                oldValues: $oldValues,

                newValues: $school->fresh()->toArray(),

                description: 'Soft deleted school.'

            );

            $this->commit();

            return $this->success(

                null,

                'School deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete school.',

                [

                    'school_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete school.'

            );

        }
    }
}
