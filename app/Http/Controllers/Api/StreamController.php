<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\StreamResource;
use App\Models\Grade;
use App\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StreamController extends BaseCrudController
{
    private const MODULE = 'Streams';

    private const RELATIONS = [
        'grade',
    ];

    public function index(Request $request)
    {
        $streams = $this->streamQuery($request)
            ->with(self::RELATIONS)
            ->orderBy('stream_name')
            ->get();

        return $this->success(
            StreamResource::collection($streams),
            'Streams retrieved successfully.'
        );
    }

    public function show(
        Request $request,
        string $id
    ) {
        $stream = $this->streamQuery($request)
            ->with(self::RELATIONS)
            ->find($id);

        if (! $stream) {
            return $this->notFound(
                'Stream not found.'
            );
        }

        return $this->success(
            new StreamResource($stream),
            'Stream retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_id' => [
                'required',
                'uuid',
            ],
            'stream_name' => [
                'required',
                'string',
                'max:50',
            ],
        ]);

        $schoolId = $this->schoolId($request);

        $gradeExists = Grade::query()
            ->where('school_id', $schoolId)
            ->whereKey($validated['grade_id'])
            ->exists();

        if (! $gradeExists) {
            throw ValidationException::withMessages([
                'grade_id' => 'The selected grade is invalid.',
            ]);
        }

        $duplicate = Stream::query()
            ->where('school_id', $schoolId)
            ->where('grade_id', $validated['grade_id'])
            ->where(
                'stream_name',
                $validated['stream_name']
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'stream_name' => 'The stream name has already been taken for this grade.',
            ]);
        }

        $stream = DB::transaction(
            function () use (
                $request,
                $validated,
                $schoolId
            ) {
                $stream = Stream::create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'grade_id' => $validated['grade_id'],
                    'stream_name' => $validated['stream_name'],
                    'active' => true,
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

                return $stream;
            }
        );

        $stream->load(self::RELATIONS);

        return $this->created(
            new StreamResource($stream),
            'Stream created successfully.'
        );
    }

    public function update(
        Request $request,
        string $id
    ) {
        $stream = $this->streamQuery($request)
            ->find($id);

        if (! $stream) {
            return $this->notFound(
                'Stream not found.'
            );
        }

        $validated = $request->validate([
            'stream_name' => [
                'sometimes',
                'string',
                'max:50',
            ],
            'active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if (
            array_key_exists(
                'stream_name',
                $validated
            )
        ) {
            $duplicate = Stream::query()
                ->where(
                    'school_id',
                    $this->schoolId($request)
                )
                ->where(
                    'grade_id',
                    $stream->grade_id
                )
                ->where(
                    'stream_name',
                    $validated['stream_name']
                )
                ->whereKeyNot($stream->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'stream_name' => 'The stream name has already been taken for this grade.',
                ]);
            }
        }

        DB::transaction(
            function () use (
                $request,
                $stream,
                $validated
            ) {
                $oldValues = $stream->toArray();

                $stream->update(
                    $validated
                );

                $stream->refresh();

                $this->audit(
                    request: $request,
                    module: self::MODULE,
                    action: 'Update',
                    model: $stream,
                    oldValues: $oldValues,
                    newValues: $stream->toArray(),
                    description: 'Updated stream.'
                );
            }
        );

        $stream->load(self::RELATIONS);

        return $this->success(
            new StreamResource($stream),
            'Stream updated successfully.'
        );
    }

    public function destroy(
        Request $request,
        string $id
    ) {
        $stream = $this->streamQuery($request)
            ->find($id);

        if (! $stream) {
            return $this->notFound(
                'Stream not found.'
            );
        }

        DB::transaction(
            function () use (
                $request,
                $stream
            ) {
                $oldValues = $stream->toArray();

                $this->audit(
                    request: $request,
                    module: self::MODULE,
                    action: 'Delete',
                    model: $stream,
                    oldValues: $oldValues,
                    newValues: null,
                    description: 'Deleted stream.'
                );

                $stream->delete();
            }
        );

        return $this->success(
            null,
            'Stream deleted successfully.'
        );
    }

    private function streamQuery(
        Request $request
    ) {
        return Stream::query()
            ->where(
                'school_id',
                $this->schoolId($request)
            );
    }

    private function schoolId(
        Request $request
    ): string {
        $schoolId =
            $request->attributes->get(
                'tenant_school_id'
            )
            ?? auth()->user()?->school_id;

        abort_unless(
            $schoolId,
            403,
            'School context not found.'
        );

        return (string) $schoolId;
    }
}
