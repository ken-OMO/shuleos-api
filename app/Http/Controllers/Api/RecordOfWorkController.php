<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\RecordOfWorkResource;
use App\Models\RecordOfWork;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordOfWorkController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Records Of Work';

    /**
     * Relationships loaded with record of work responses.
     */
    private const RELATIONS = [

        'lessonPlan',

        'curriculumCoverage',

    ];

    /**
     * Display a listing of records of work.
     */
    public function index()
    {
        $records = RecordOfWork::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('date_taught')
        ->paginate(20);

        return $this->success(

            RecordOfWorkResource::collection(

                $records

            ),

            'Records of work retrieved successfully.'

        );
    }

    /**
     * Display the specified record of work.
     */
    public function show(string $id)
    {
        $record = RecordOfWork::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($record)) {

            return $this->notFound(

                'Record of work not found.'

            );

        }

        return $this->success(

            new RecordOfWorkResource(

                $record

            ),

            'Record of work retrieved successfully.'

        );
    }
        /**
     * Store a newly created record of work.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'lesson_plan_id' => 'required|exists:lesson_plans,id',

            'date_taught' => 'required|date',

            'content_covered' => 'required|string',

            'learner_response' => 'nullable|string',

            'teacher_reflection' => 'nullable|string',

            'status' => 'sometimes|in:completed,partially_completed,postponed,cancelled',

            'created_by' => 'required|exists:users,id',

        ]);

        if (

            RecordOfWork::where(

                'lesson_plan_id',

                $validated['lesson_plan_id']

            )

            ->where('is_deleted', false)

            ->exists()

        ) {

            return $this->badRequest(

                'A record of work already exists for this lesson plan.'

            );

        }

        $this->beginTransaction();

        try {

            $record = RecordOfWork::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'lesson_plan_id' => $validated['lesson_plan_id'],

                'date_taught' => $validated['date_taught'],

                'content_covered' => $validated['content_covered'],

                'learner_response' => $validated['learner_response'] ?? null,

                'teacher_reflection' => $validated['teacher_reflection'] ?? null,

                'status' => $validated['status'] ?? 'completed',

                'created_by' => $validated['created_by'],

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            /*
             |--------------------------------------------------------------------------
             | Future Automation Hook
             |--------------------------------------------------------------------------
             |
             | Later this is where ShuleOS will automatically:
             |
             | - Update Curriculum Coverage
             | - Update Teacher Dashboard
             | - Update HOD Dashboard
             | - Update Principal Dashboard
             |
             */

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $record,

                oldValues: null,

                newValues: $record->toArray(),

                description: 'Created record of work.'

            );

            $this->commit();

            $this->loadRelations(

                $record,

                self::RELATIONS

            );

            return $this->created(

                new RecordOfWorkResource(

                    $record

                ),

                'Record of work created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create record of work.',

                [

                    'lesson_plan_id' => $request->lesson_plan_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create record of work.'

            );

        }
    }

    /**
     * Update the specified record of work.
     */
    public function update(Request $request, string $id)
    {
        $record = RecordOfWork::find($id);

        if ($this->modelNotFound($record)) {

            return $this->notFound(

                'Record of work not found.'

            );

        }

        if ($this->isDeleted($record)) {

            return $this->badRequest(

                'Record of work has been deleted.'

            );

        }

        $validated = $request->validate([

            'date_taught' => 'sometimes|date',

            'content_covered' => 'sometimes|string',

            'learner_response' => 'nullable|string',

            'teacher_reflection' => 'nullable|string',

            'status' => 'sometimes|in:completed,partially_completed,postponed,cancelled',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $record->toArray();

            $record->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $record,

                oldValues: $oldValues,

                newValues: $record->fresh()->toArray(),

                description: 'Updated record of work.'

            );

            $this->commit();

            $this->loadRelations(

                $record,

                self::RELATIONS

            );

            return $this->success(

                new RecordOfWorkResource(

                    $record

                ),

                'Record of work updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update record of work.',

                [

                    'record_of_work_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update record of work.'

            );

        }
    }

    /**
     * Soft delete the specified record of work.
     */
    public function destroy(Request $request, string $id)
    {
        $record = RecordOfWork::find($id);

        if ($this->modelNotFound($record)) {

            return $this->notFound(

                'Record of work not found.'

            );

        }

        if ($this->isDeleted($record)) {

            return $this->badRequest(

                'Record of work has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $record->toArray();

            $record->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $record,

                oldValues: $oldValues,

                newValues: $record->fresh()->toArray(),

                description: 'Soft deleted record of work.'

            );

            $this->commit();

            return $this->success(

                null,

                'Record of work deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete record of work.',

                [

                    'record_of_work_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete record of work.'

            );

        }
    }
}
