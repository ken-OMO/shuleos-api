<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LearnerResource;
use App\Models\Learner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LearnerController extends BaseCrudController
{
    /**
     * Module name for audit logging.
     */
    private const MODULE = 'Learners';

    /**
     * Relationships to eager load.
     */
    private const RELATIONS = [

        'school',

        'grade',

        'stream',

    ];

    /**
     * Display a listing of learners.
     */
    public function index()
    {
        $learners = Learner::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(

            LearnerResource::collection(

                $learners

            ),

            'Learners retrieved successfully.'

        );
    }

    /**
     * Display the specified learner.
     */
    public function show(string $id)
    {
        $learner = Learner::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->find($id);

        if ($this->modelNotFound($learner)) {

            return $this->notFound(

                'Learner not found.'

            );

        }

        return $this->success(

            new LearnerResource(

                $learner

            ),

            'Learner retrieved successfully.'

        );
    }

    /**
     * Store a newly created learner.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'grade_id' => 'required|exists:grades,id',

            'stream_id' => 'required|exists:streams,id',

            'admission_no' => 'required|unique:learners,admission_no',

            'first_name' => 'required|string|max:100',

            'middle_name' => 'nullable|string|max:100',

            'last_name' => 'required|string|max:100',

            'gender' => 'nullable|string|max:20',

            'date_of_birth' => 'nullable|date',

            'admission_date' => 'nullable|date',

            'upi' => 'nullable|string|max:100',

            'assessment_no' => 'nullable|string|max:100',

        ]);

        $this->beginTransaction();

        try {

            $learner = Learner::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'grade_id' => $validated['grade_id'],

                'stream_id' => $validated['stream_id'],

                'admission_no' => $validated['admission_no'],

                'first_name' => $validated['first_name'],

                'middle_name' => $validated['middle_name'] ?? null,

                'last_name' => $validated['last_name'],

                'gender' => $validated['gender'] ?? null,

                'date_of_birth' => $validated['date_of_birth'] ?? null,

                'admission_date' => $validated['admission_date'] ?? null,

                'upi' => $validated['upi'] ?? null,

                'assessment_no' => $validated['assessment_no'] ?? null,

                'active' => true,

                'is_deleted' => false,

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $learner,

                oldValues: null,

                newValues: $learner->toArray(),

                description: 'Created learner.'

            );

            $this->commit();

            $this->loadRelations(

                $learner,

                self::RELATIONS

            );

            return $this->created(

                new LearnerResource(

                    $learner

                ),

                'Learner created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create learner.',

                [

                    'school_id' => $request->school_id,

                    'admission_no' => $request->admission_no,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create learner.'

            );

        }
    }

    /**
     * Update the specified learner.
     */
    public function update(Request $request, string $id)
    {
        $learner = Learner::find($id);

        if ($this->modelNotFound($learner)) {

            return $this->notFound(

                'Learner not found.'

            );

        }

        if ($this->isDeleted($learner)) {

            return $this->badRequest(

                'Learner has been deleted.'

            );

        }

        $validated = $request->validate([

            'admission_no' => 'sometimes|unique:learners,admission_no,'.$id.',id',

            'first_name' => 'sometimes|string|max:100',

            'middle_name' => 'nullable|string|max:100',

            'last_name' => 'sometimes|string|max:100',

            'gender' => 'nullable|string|max:20',

            'date_of_birth' => 'nullable|date',

            'grade_id' => 'sometimes|exists:grades,id',

            'stream_id' => 'sometimes|exists:streams,id',

            'admission_date' => 'nullable|date',

            'upi' => 'nullable|string|max:100',

            'assessment_no' => 'nullable|string|max:100',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $learner->toArray();

            $learner->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $learner,

                oldValues: $oldValues,

                newValues: $learner->fresh()->toArray(),

                description: 'Updated learner profile.'

            );

            $this->commit();

            $this->loadRelations(

                $learner,

                self::RELATIONS

            );

            return $this->success(

                new LearnerResource(

                    $learner

                ),

                'Learner updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update learner.',

                [

                    'learner_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update learner.'

            );

        }
    }

    /**
     * Soft delete the specified learner.
     */
    public function destroy(Request $request, string $id)
    {
        $learner = Learner::find($id);

        if ($this->modelNotFound($learner)) {

            return $this->notFound(

                'Learner not found.'

            );

        }

        if ($this->isDeleted($learner)) {

            return $this->badRequest(

                'Learner has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $learner->toArray();

            $learner->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $learner,

                oldValues: $oldValues,

                newValues: $learner->fresh()->toArray(),

                description: 'Soft deleted learner.'

            );

            $this->commit();

            return $this->success(

                null,

                'Learner deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete learner.',

                [

                    'learner_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete learner.'

            );

        }
    }
}
