<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\SchemeLessonResource;
use App\Models\SchemeLesson;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SchemeLessonController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Scheme Lessons';

    /**
     * Relationships loaded with lesson responses.
     */
    private const RELATIONS = [

        'scheme',

        'week',

        'lessonPlans',

    ];

    /**
     * Display a listing of scheme lessons.
     */
    public function index()
    {
        $lessons = SchemeLesson::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderBy('lesson_number')
        ->paginate(20);

        return $this->success(

            SchemeLessonResource::collection(

                $lessons

            ),

            'Scheme lessons retrieved successfully.'

        );
    }

    /**
     * Display the specified scheme lesson.
     */
    public function show(string $id)
    {
        $lesson = SchemeLesson::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($lesson)) {

            return $this->notFound(

                'Scheme lesson not found.'

            );

        }

        return $this->success(

            new SchemeLessonResource(

                $lesson

            ),

            'Scheme lesson retrieved successfully.'

        );
    }
        /**
     * Store a newly created scheme lesson.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'scheme_id' => 'required|exists:schemes_of_work,id',

            'week_id' => 'required|exists:academic_weeks,id',

            'lesson_number' => 'required|integer|min:1',

            'strand' => 'required|string|max:255',

            'sub_strand' => 'required|string|max:255',

            'specific_learning_outcome' => 'required|string',

            'learning_experience' => 'required|string',

            'resources' => 'nullable|string',

            'assessment_method' => 'nullable|string',

        ]);

        if (

            SchemeLesson::where('scheme_id', $validated['scheme_id'])

                ->where('lesson_number', $validated['lesson_number'])

                ->where('is_deleted', false)

                ->exists()

        ) {

            return $this->badRequest(

                'Lesson number already exists in this scheme.'

            );

        }

        $this->beginTransaction();

        try {

            $lesson = SchemeLesson::create([

                'id' => (string) Str::uuid(),

                'scheme_id' => $validated['scheme_id'],

                'week_id' => $validated['week_id'],

                'lesson_number' => $validated['lesson_number'],

                'strand' => $validated['strand'],

                'sub_strand' => $validated['sub_strand'],

                'specific_learning_outcome' => $validated['specific_learning_outcome'],

                'learning_experience' => $validated['learning_experience'],

                'resources' => $validated['resources'] ?? null,

                'assessment_method' => $validated['assessment_method'] ?? null,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $lesson,

                oldValues: null,

                newValues: $lesson->toArray(),

                description: 'Created scheme lesson.'

            );

            $this->commit();

            $this->loadRelations(

                $lesson,

                self::RELATIONS

            );

            return $this->created(

                new SchemeLessonResource(

                    $lesson

                ),

                'Scheme lesson created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create scheme lesson.',

                [

                    'scheme_id' => $request->scheme_id,

                    'lesson_number' => $request->lesson_number,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create scheme lesson.'

            );

        }
    }

    /**
     * Update the specified scheme lesson.
     */
    public function update(Request $request, string $id)
    {
        $lesson = SchemeLesson::find($id);

        if ($this->modelNotFound($lesson)) {

            return $this->notFound(

                'Scheme lesson not found.'

            );

        }

        if ($this->isDeleted($lesson)) {

            return $this->badRequest(

                'Scheme lesson has been deleted.'

            );

        }

        $validated = $request->validate([

            'lesson_number' => 'sometimes|integer|min:1',

            'strand' => 'sometimes|string|max:255',

            'sub_strand' => 'sometimes|string|max:255',

            'specific_learning_outcome' => 'sometimes|string',

            'learning_experience' => 'sometimes|string',

            'resources' => 'nullable|string',

            'assessment_method' => 'nullable|string',

        ]);

        if (

            isset($validated['lesson_number']) &&

            SchemeLesson::where('scheme_id', $lesson->scheme_id)

                ->where('lesson_number', $validated['lesson_number'])

                ->where('id', '!=', $lesson->id)

                ->where('is_deleted', false)

                ->exists()

        ) {

            return $this->badRequest(

                'Lesson number already exists in this scheme.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $lesson->toArray();

            $lesson->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $lesson,

                oldValues: $oldValues,

                newValues: $lesson->fresh()->toArray(),

                description: 'Updated scheme lesson.'

            );

            $this->commit();

            $this->loadRelations(

                $lesson,

                self::RELATIONS

            );

            return $this->success(

                new SchemeLessonResource(

                    $lesson

                ),

                'Scheme lesson updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update scheme lesson.',

                [

                    'scheme_lesson_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update scheme lesson.'

            );

        }
    }

    /**
     * Soft delete the specified scheme lesson.
     */
    public function destroy(Request $request, string $id)
    {
        $lesson = SchemeLesson::find($id);

        if ($this->modelNotFound($lesson)) {

            return $this->notFound(

                'Scheme lesson not found.'

            );

        }

        if ($this->isDeleted($lesson)) {

            return $this->badRequest(

                'Scheme lesson has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $lesson->toArray();

            $lesson->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $lesson,

                oldValues: $oldValues,

                newValues: $lesson->fresh()->toArray(),

                description: 'Soft deleted scheme lesson.'

            );

            $this->commit();

            return $this->success(

                null,

                'Scheme lesson deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete scheme lesson.',

                [

                    'scheme_lesson_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete scheme lesson.'

            );

        }
    }
}
