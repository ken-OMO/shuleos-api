<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\GradeResource;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GradeController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Grades';

    /**
     * Relationships loaded with grade responses.
     */
    private const RELATIONS = [

        'school',

        'educationLevel',

    ];

    /**
     * Display a listing of grades.
     */
    public function index()
    {
        $grades = Grade::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderBy('grade_order')
        ->get();

        return $this->success(

            GradeResource::collection(

                $grades

            ),

            'Grades retrieved successfully.'

        );
    }

    /**
     * Display the specified grade.
     */
    public function show(string $id)
    {
        $grade = Grade::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($grade)) {

            return $this->notFound(

                'Grade not found.'

            );

        }

        return $this->success(

            new GradeResource(

                $grade

            ),

            'Grade retrieved successfully.'

        );
    }
        /**
     * Store a newly created grade.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'education_level_id' => 'required|exists:education_levels,id',

            'grade_name' => 'required|string|max:100',

            'grade_order' => 'required|integer',

        ]);

        $this->beginTransaction();

        try {

            $grade = Grade::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'education_level_id' => $validated['education_level_id'],

                'grade_name' => $validated['grade_name'],

                'grade_order' => $validated['grade_order'],

                'active' => true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $grade,

                oldValues: null,

                newValues: $grade->toArray(),

                description: 'Created grade.'

            );

            $this->commit();

            $this->loadRelations(

                $grade,

                self::RELATIONS

            );

            return $this->created(

                new GradeResource(

                    $grade

                ),

                'Grade created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create grade.',

                [

                    'school_id' => $request->school_id,

                    'education_level_id' => $request->education_level_id,

                    'grade_name' => $request->grade_name,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create grade.'

            );

        }
    }
        /**
     * Update the specified grade.
     */
    public function update(Request $request, string $id)
    {
        $grade = Grade::find($id);

        if ($this->modelNotFound($grade)) {

            return $this->notFound(

                'Grade not found.'

            );

        }

        if ($this->isDeleted($grade)) {

            return $this->badRequest(

                'Grade has been deleted.'

            );

        }

        $validated = $request->validate([

            'grade_name' => 'sometimes|string|max:100',

            'grade_order' => 'sometimes|integer',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $grade->toArray();

            $grade->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $grade,

                oldValues: $oldValues,

                newValues: $grade->fresh()->toArray(),

                description: 'Updated grade.'

            );

            $this->commit();

            $this->loadRelations(

                $grade,

                self::RELATIONS

            );

            return $this->success(

                new GradeResource(

                    $grade

                ),

                'Grade updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update grade.',

                [

                    'grade_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update grade.'

            );

        }
    }
        /**
     * Soft delete the specified grade.
     */
    public function destroy(Request $request, string $id)
    {
        $grade = Grade::find($id);

        if ($this->modelNotFound($grade)) {

            return $this->notFound(

                'Grade not found.'

            );

        }

        if ($this->isDeleted($grade)) {

            return $this->badRequest(

                'Grade has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $grade->toArray();

            $grade->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $grade,

                oldValues: $oldValues,

                newValues: $grade->fresh()->toArray(),

                description: 'Soft deleted grade.'

            );

            $this->commit();

            return $this->success(

                null,

                'Grade deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete grade.',

                [

                    'grade_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete grade.'

            );

        }
    }
}
