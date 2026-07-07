<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LessonNoteResource;
use App\Models\LessonNote;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LessonNoteController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Lesson Notes';

    /**
     * Relationships loaded with lesson note responses.
     */
    private const RELATIONS = [

        'lessonPlan',

    ];

    /**
     * Display a listing of lesson notes.
     */
    public function index()
    {
        $notes = LessonNote::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('created_at')
        ->paginate(20);

        return $this->success(

            LessonNoteResource::collection(

                $notes

            ),

            'Lesson notes retrieved successfully.'

        );
    }

    /**
     * Display the specified lesson note.
     */
    public function show(string $id)
    {
        $note = LessonNote::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($note)) {

            return $this->notFound(

                'Lesson note not found.'

            );

        }

        return $this->success(

            new LessonNoteResource(

                $note

            ),

            'Lesson note retrieved successfully.'

        );
    }
        /**
     * Store a newly created lesson note.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'lesson_plan_id' => 'required|exists:lesson_plans,id',

            'note_content' => 'required|string',

            'created_by' => 'required|exists:users,id',

        ]);

        if (

            LessonNote::where(

                'lesson_plan_id',

                $validated['lesson_plan_id']

            )

            ->where('is_deleted', false)

            ->exists()

        ) {

            return $this->badRequest(

                'A lesson note already exists for this lesson plan.'

            );

        }

        $this->beginTransaction();

        try {

            $note = LessonNote::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'lesson_plan_id' => $validated['lesson_plan_id'],

                'note_content' => $validated['note_content'],

                'created_by' => $validated['created_by'],

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $note,

                oldValues: null,

                newValues: $note->toArray(),

                description: 'Created lesson note.'

            );

            $this->commit();

            $this->loadRelations(

                $note,

                self::RELATIONS

            );

            return $this->created(

                new LessonNoteResource(

                    $note

                ),

                'Lesson note created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create lesson note.',

                [

                    'lesson_plan_id' => $request->lesson_plan_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create lesson note.'

            );

        }
    }

    /**
     * Update the specified lesson note.
     */
    public function update(Request $request, string $id)
    {
        $note = LessonNote::find($id);

        if ($this->modelNotFound($note)) {

            return $this->notFound(

                'Lesson note not found.'

            );

        }

        if ($this->isDeleted($note)) {

            return $this->badRequest(

                'Lesson note has been deleted.'

            );

        }

        $validated = $request->validate([

            'note_content' => 'sometimes|string',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $note->toArray();

            $note->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $note,

                oldValues: $oldValues,

                newValues: $note->fresh()->toArray(),

                description: 'Updated lesson note.'

            );

            $this->commit();

            $this->loadRelations(

                $note,

                self::RELATIONS

            );

            return $this->success(

                new LessonNoteResource(

                    $note

                ),

                'Lesson note updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update lesson note.',

                [

                    'lesson_note_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update lesson note.'

            );

        }
    }

    /**
     * Soft delete the specified lesson note.
     */
    public function destroy(Request $request, string $id)
    {
        $note = LessonNote::find($id);

        if ($this->modelNotFound($note)) {

            return $this->notFound(

                'Lesson note not found.'

            );

        }

        if ($this->isDeleted($note)) {

            return $this->badRequest(

                'Lesson note has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $note->toArray();

            $note->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $note,

                oldValues: $oldValues,

                newValues: $note->fresh()->toArray(),

                description: 'Soft deleted lesson note.'

            );

            $this->commit();

            return $this->success(

                null,

                'Lesson note deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete lesson note.',

                [

                    'lesson_note_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete lesson note.'

            );

        }
    }
}
