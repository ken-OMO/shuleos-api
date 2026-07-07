<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\CurriculumCoverageResource;
use App\Models\CurriculumCoverage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CurriculumCoverageController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Curriculum Coverage';

    /**
     * Relationships loaded with curriculum coverage responses.
     */
    private const RELATIONS = [

        'teacherAssignment',

        'scheme',

        'schemeLesson',

        'recordOfWork',

    ];

    /**
     * Display a listing of curriculum coverage records.
     */
    public function index()
    {
        $coverage = CurriculumCoverage::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('date_completed')
        ->paginate(20);

        return $this->success(

            CurriculumCoverageResource::collection(

                $coverage

            ),

            'Curriculum coverage records retrieved successfully.'

        );
    }

    /**
     * Display the specified curriculum coverage record.
     */
    public function show(string $id)
    {
        $coverage = CurriculumCoverage::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($coverage)) {

            return $this->notFound(

                'Curriculum coverage record not found.'

            );

        }

        return $this->success(

            new CurriculumCoverageResource(

                $coverage

            ),

            'Curriculum coverage record retrieved successfully.'

        );
    }
        /**
     * Store a newly created curriculum coverage record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'teacher_assignment_id' => 'required|exists:teacher_assignments,id',

            'scheme_id' => 'required|exists:schemes_of_work,id',

            'scheme_lesson_id' => 'required|exists:scheme_lessons,id',

            'record_of_work_id' => 'required|exists:records_of_work,id',

            'date_completed' => 'required|date',

            'strand' => 'required|string|max:255',

            'sub_strand' => 'required|string|max:255',

            'week_number' => 'required|integer|min:1',

        ]);

        if (

            CurriculumCoverage::where(

                'record_of_work_id',

                $validated['record_of_work_id']

            )

            ->where('is_deleted', false)

            ->exists()

        ) {

            return $this->badRequest(

                'Curriculum coverage already exists for this record of work.'

            );

        }

        $this->beginTransaction();

        try {

            $coverage = CurriculumCoverage::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'teacher_assignment_id' => $validated['teacher_assignment_id'],

                'scheme_id' => $validated['scheme_id'],

                'scheme_lesson_id' => $validated['scheme_lesson_id'],

                'record_of_work_id' => $validated['record_of_work_id'],

                'date_completed' => $validated['date_completed'],

                'strand' => $validated['strand'],

                'sub_strand' => $validated['sub_strand'],

                'week_number' => $validated['week_number'],

                'completed' => true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            /*
             |--------------------------------------------------------------------------
             | Future Automation Hook
             |--------------------------------------------------------------------------
             |
             | Later this module can automatically:
             |
             | - Update Teacher Progress Dashboard
             | - Update HOD Dashboard
             | - Update Principal Dashboard
             | - Generate CBC Coverage Reports
             | - Generate End-Term Coverage Analysis
             |
             */

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $coverage,

                oldValues: null,

                newValues: $coverage->toArray(),

                description: 'Created curriculum coverage record.'

            );

            $this->commit();

            $this->loadRelations(

                $coverage,

                self::RELATIONS

            );

            return $this->created(

                new CurriculumCoverageResource(

                    $coverage

                ),

                'Curriculum coverage created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create curriculum coverage.',

                [

                    'record_of_work_id' => $request->record_of_work_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create curriculum coverage.'

            );

        }
    }

    /**
     * Update the specified curriculum coverage record.
     */
    public function update(Request $request, string $id)
    {
        $coverage = CurriculumCoverage::find($id);

        if ($this->modelNotFound($coverage)) {

            return $this->notFound(

                'Curriculum coverage record not found.'

            );

        }

        if ($this->isDeleted($coverage)) {

            return $this->badRequest(

                'Curriculum coverage record has been deleted.'

            );

        }

        $validated = $request->validate([

            'date_completed' => 'sometimes|date',

            'strand' => 'sometimes|string|max:255',

            'sub_strand' => 'sometimes|string|max:255',

            'week_number' => 'sometimes|integer|min:1',

            'completed' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $coverage->toArray();

            $coverage->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $coverage,

                oldValues: $oldValues,

                newValues: $coverage->fresh()->toArray(),

                description: 'Updated curriculum coverage record.'

            );

            $this->commit();

            $this->loadRelations(

                $coverage,

                self::RELATIONS

            );

            return $this->success(

                new CurriculumCoverageResource(

                    $coverage

                ),

                'Curriculum coverage updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update curriculum coverage.',

                [

                    'curriculum_coverage_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update curriculum coverage.'

            );

        }
    }

    /**
     * Soft delete the specified curriculum coverage record.
     */
    public function destroy(Request $request, string $id)
    {
        $coverage = CurriculumCoverage::find($id);

        if ($this->modelNotFound($coverage)) {

            return $this->notFound(

                'Curriculum coverage record not found.'

            );

        }

        if ($this->isDeleted($coverage)) {

            return $this->badRequest(

                'Curriculum coverage record has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $coverage->toArray();

            $coverage->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $coverage,

                oldValues: $oldValues,

                newValues: $coverage->fresh()->toArray(),

                description: 'Soft deleted curriculum coverage record.'

            );

            $this->commit();

            return $this->success(

                null,

                'Curriculum coverage deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete curriculum coverage.',

                [

                    'curriculum_coverage_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete curriculum coverage.'

            );

        }
    }
}
