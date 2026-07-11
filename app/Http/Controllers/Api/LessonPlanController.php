<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LessonPlanResource;
use App\Models\LessonPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LessonPlanController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Lesson Plans';

    /**
     * Relationships loaded with lesson plan responses.
     */
    private const RELATIONS = [

        'assignment',

        'schemeLesson',

        'notes',

        'recordsOfWork',

    ];

    /**
     * Display a listing of lesson plans.
     */
    public function index()
    {
        $plans = LessonPlan::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderBy('lesson_date')
        ->paginate(20);

        return $this->success(

            LessonPlanResource::collection(

                $plans

            ),

            'Lesson plans retrieved successfully.'

        );
    }

    /**
     * Display the specified lesson plan.
     */
    public function show(string $id)
    {
        $plan = LessonPlan::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($plan)) {

            return $this->notFound(

                'Lesson plan not found.'

            );

        }

        return $this->success(

            new LessonPlanResource(

                $plan

            ),

            'Lesson plan retrieved successfully.'

        );
    }
        /**
     * Store a newly created lesson plan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'teacher_assignment_id' => 'required|exists:teacher_assignments,id',

            'scheme_lesson_id' => 'required|exists:scheme_lessons,id',

            'lesson_date' => 'required|date',

            'introduction' => 'nullable|string',

            'lesson_development' => 'required|string',

            'conclusion' => 'nullable|string',

            'reflection' => 'nullable|string',

            'status' => 'sometimes|in:draft,submitted,approved,rejected',

            'created_by' => 'required|exists:users,id',

        ]);

        if (

            LessonPlan::where(

                'teacher_assignment_id',

                $validated['teacher_assignment_id']

            )

            ->where(

                'scheme_lesson_id',

                $validated['scheme_lesson_id']

            )

            ->where('is_deleted', false)

            ->exists()

        ) {

            return $this->badRequest(

                'A lesson plan already exists for this teacher assignment and scheme lesson.'

            );

        }

        $this->beginTransaction();

        try {

            $plan = LessonPlan::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'teacher_assignment_id' => $validated['teacher_assignment_id'],

                'scheme_lesson_id' => $validated['scheme_lesson_id'],

                'lesson_date' => $validated['lesson_date'],

                'introduction' => $validated['introduction'] ?? null,

                'lesson_development' => $validated['lesson_development'],

                'conclusion' => $validated['conclusion'] ?? null,

                'reflection' => $validated['reflection'] ?? null,

                'status' => $validated['status'] ?? 'draft',

                'created_by' => $validated['created_by'],

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $plan,

                oldValues: null,

                newValues: $plan->toArray(),

                description: 'Created lesson plan.'

            );

            $this->commit();

            $this->loadRelations(

                $plan,

                self::RELATIONS

            );

            return $this->created(

                new LessonPlanResource(

                    $plan

                ),

                'Lesson plan created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create lesson plan.',

                [

                    'teacher_assignment_id' => $request->teacher_assignment_id,

                    'scheme_lesson_id' => $request->scheme_lesson_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create lesson plan.'

            );

        }
    }

    /**
     * Update the specified lesson plan.
     */
    public function update(Request $request, string $id)
    {
        $plan = LessonPlan::find($id);

        if ($this->modelNotFound($plan)) {

            return $this->notFound(

                'Lesson plan not found.'

            );

        }

        if ($this->isDeleted($plan)) {

            return $this->badRequest(

                'Lesson plan has been deleted.'

            );

        }

        $validated = $request->validate([

            'lesson_date' => 'sometimes|date',

            'introduction' => 'nullable|string',

            'lesson_development' => 'sometimes|string',

            'conclusion' => 'nullable|string',

            'reflection' => 'nullable|string',

            'status' => 'sometimes|in:draft,submitted,approved,rejected',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $plan->toArray();

            $plan->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $plan,

                oldValues: $oldValues,

                newValues: $plan->fresh()->toArray(),

                description: 'Updated lesson plan.'

            );

            $this->commit();

            $this->loadRelations(

                $plan,

                self::RELATIONS

            );

            return $this->success(

                new LessonPlanResource(

                    $plan

                ),

                'Lesson plan updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update lesson plan.',

                [

                    'lesson_plan_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update lesson plan.'

            );

        }
    }

    /**
     * Soft delete the specified lesson plan.
     */
    public function destroy(Request $request, string $id)
    {
        $plan = LessonPlan::find($id);

        if ($this->modelNotFound($plan)) {

            return $this->notFound(

                'Lesson plan not found.'

            );

        }

        if ($this->isDeleted($plan)) {

            return $this->badRequest(

                'Lesson plan has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $plan->toArray();

            $plan->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $plan,

                oldValues: $oldValues,

                newValues: $plan->fresh()->toArray(),

                description: 'Soft deleted lesson plan.'

            );

            $this->commit();

            return $this->success(

                null,

                'Lesson plan deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete lesson plan.',

                [

                    'lesson_plan_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete lesson plan.'

            );

        }
    }
}
