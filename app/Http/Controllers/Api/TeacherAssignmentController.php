<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeacherAssignmentController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Teacher Assignments';

    /**
     * Relationships loaded with teacher assignment responses.
     */
    private const RELATIONS = [

        'teacher',

        'learningArea',

        'grade',

        'stream',

        'academicYear',

        'term',

        'lessonPlans',

    ];

    /**
     * Display a listing of teacher assignments.
     */
    public function index()
    {
        $assignments = TeacherAssignment::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('created_at')
        ->paginate(20);

        return $this->success(

            TeacherAssignmentResource::collection(

                $assignments

            ),

            'Teacher assignments retrieved successfully.'

        );
    }

    /**
     * Display the specified teacher assignment.
     */
    public function show(string $id)
    {
        $assignment = TeacherAssignment::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($assignment)) {

            return $this->notFound(

                'Teacher assignment not found.'

            );

        }

        return $this->success(

            new TeacherAssignmentResource(

                $assignment

            ),

            'Teacher assignment retrieved successfully.'

        );
    }
        /**
     * Store a newly created teacher assignment.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'teacher_id' => 'required|exists:teachers,id',

            'learning_area_id' => 'required|exists:learning_areas,id',

            'grade_id' => 'required|exists:grades,id',

            'stream_id' => 'required|exists:streams,id',

            'academic_year_id' => 'required|exists:academic_years,id',

            'term_id' => 'required|exists:terms,id',

            'lessons_per_week' => 'required|integer|min:1',

            'is_class_teacher' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $assignment = TeacherAssignment::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'teacher_id' => $validated['teacher_id'],

                'learning_area_id' => $validated['learning_area_id'],

                'grade_id' => $validated['grade_id'],

                'stream_id' => $validated['stream_id'],

                'academic_year_id' => $validated['academic_year_id'],

                'term_id' => $validated['term_id'],

                'lessons_per_week' => $validated['lessons_per_week'],

                'is_class_teacher' => $validated['is_class_teacher'] ?? false,

                'active' => true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $assignment,

                oldValues: null,

                newValues: $assignment->toArray(),

                description: 'Created teacher assignment.'

            );

            $this->commit();

            $this->loadRelations(

                $assignment,

                self::RELATIONS

            );

            return $this->created(

                new TeacherAssignmentResource(

                    $assignment

                ),

                'Teacher assignment created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create teacher assignment.',

                [

                    'teacher_id' => $request->teacher_id,

                    'learning_area_id' => $request->learning_area_id,

                    'grade_id' => $request->grade_id,

                    'stream_id' => $request->stream_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create teacher assignment.'

            );

        }
    }

    /**
     * Update the specified teacher assignment.
     */
    public function update(Request $request, string $id)
    {
        $assignment = TeacherAssignment::find($id);

        if ($this->modelNotFound($assignment)) {

            return $this->notFound(

                'Teacher assignment not found.'

            );

        }

        if ($this->isDeleted($assignment)) {

            return $this->badRequest(

                'Teacher assignment has been deleted.'

            );

        }

        $validated = $request->validate([

            'lessons_per_week' => 'sometimes|integer|min:1',

            'is_class_teacher' => 'sometimes|boolean',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $assignment->toArray();

            $assignment->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $assignment,

                oldValues: $oldValues,

                newValues: $assignment->fresh()->toArray(),

                description: 'Updated teacher assignment.'

            );

            $this->commit();

            $this->loadRelations(

                $assignment,

                self::RELATIONS

            );

            return $this->success(

                new TeacherAssignmentResource(

                    $assignment

                ),

                'Teacher assignment updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update teacher assignment.',

                [

                    'teacher_assignment_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update teacher assignment.'

            );

        }
    }

    /**
     * Soft delete the specified teacher assignment.
     */
    public function destroy(Request $request, string $id)
    {
        $assignment = TeacherAssignment::find($id);

        if ($this->modelNotFound($assignment)) {

            return $this->notFound(

                'Teacher assignment not found.'

            );

        }

        if ($this->isDeleted($assignment)) {

            return $this->badRequest(

                'Teacher assignment has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $assignment->toArray();

            $assignment->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $assignment,

                oldValues: $oldValues,

                newValues: $assignment->fresh()->toArray(),

                description: 'Soft deleted teacher assignment.'

            );

            $this->commit();

            return $this->success(

                null,

                'Teacher assignment deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete teacher assignment.',

                [

                    'teacher_assignment_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete teacher assignment.'

            );

        }
    }
}
