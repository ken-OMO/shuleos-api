<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\StreamResource;
use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StreamController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Streams';

    /**
     * Relationships loaded with stream responses.
     */
    private const RELATIONS = [

        'grade',

        'school',

    ];

    /**
     * Display a listing of streams.
     */
    public function index()
    {
        $streams = Stream::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderBy('stream_name')
        ->get();

        return $this->success(

            StreamResource::collection(

                $streams

            ),

            'Streams retrieved successfully.'

        );
    }

    /**
     * Display the specified stream.
     */
    public function show(string $id)
    {
        $stream = Stream::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($stream)) {

            return $this->notFound(

                'Stream not found.'

            );

        }

        return $this->success(

            new StreamResource(

                $stream

            ),

            'Stream retrieved successfully.'

        );
    }
        /**
     * Store a newly created stream.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'grade_id' => 'required|exists:grades,id',

            'stream_name' => 'required|string|max:50',

        ]);

        $this->beginTransaction();

        try {

            $stream = Stream::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'grade_id' => $validated['grade_id'],

                'stream_name' => $validated['stream_name'],

                'active' => true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $stream,

                oldValues: null,

                newValues: $stream->toArray(),

                description: 'Created stream.'

            );

            $this->commit();

            $this->loadRelations(

                $stream,

                self::RELATIONS

            );

            return $this->created(

                new StreamResource(

                    $stream

                ),

                'Stream created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create stream.',

                [

                    'school_id' => $request->school_id,

                    'grade_id' => $request->grade_id,

                    'stream_name' => $request->stream_name,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create stream.'

            );

        }
    }
        /**
     * Update the specified stream.
     */
    public function update(Request $request, string $id)
    {
        $stream = Stream::find($id);

        if ($this->modelNotFound($stream)) {

            return $this->notFound(

                'Stream not found.'

            );

        }

        if ($this->isDeleted($stream)) {

            return $this->badRequest(

                'Stream has been deleted.'

            );

        }

        $validated = $request->validate([

            'stream_name' => 'sometimes|string|max:50',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $stream->toArray();

            $stream->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $stream,

                oldValues: $oldValues,

                newValues: $stream->fresh()->toArray(),

                description: 'Updated stream.'

            );

            $this->commit();

            $this->loadRelations(

                $stream,

                self::RELATIONS

            );

            return $this->success(

                new StreamResource(

                    $stream

                ),

                'Stream updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update stream.',

                [

                    'stream_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update stream.'

            );

        }
    }
        /**
     * Soft delete the specified stream.
     */
    public function destroy(Request $request, string $id)
    {
        $stream = Stream::find($id);

        if ($this->modelNotFound($stream)) {

            return $this->notFound(

                'Stream not found.'

            );

        }

        if ($this->isDeleted($stream)) {

            return $this->badRequest(

                'Stream has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $stream->toArray();

            $stream->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $stream,

                oldValues: $oldValues,

                newValues: $stream->fresh()->toArray(),

                description: 'Soft deleted stream.'

            );

            $this->commit();

            return $this->success(

                null,

                'Stream deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete stream.',

                [

                    'stream_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete stream.'

            );

        }
    }
}
