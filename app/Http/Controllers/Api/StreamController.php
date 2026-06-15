<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StreamResource;
use App\Models\Stream;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StreamController extends Controller
{
    /**
     * List Streams
     */
    public function index()
    {
        $streams = Stream::with([
                'grade',
                'school'
            ])
            ->orderBy('stream_name')
            ->get();

        return response()->json([

            'success' => true,

            'data' => StreamResource::collection(
                $streams
            )

        ]);
    }

    /**
     * Get Single Stream
     */
    public function show($id)
    {
        $stream = Stream::with([
                'grade',
                'school'
            ])
            ->find($id);

        if (!$stream) {

            return response()->json([

                'success' => false,

                'message' => 'Stream not found'

            ], 404);
        }

        return response()->json([

            'success' => true,

            'data' => new StreamResource(
                $stream
            )

        ]);
    }

    /**
     * Create Stream
     */
    public function store(Request $request)
    {
        $request->validate([

            'school_id' => 'required|exists:schools,id',

            'grade_id' => 'required|exists:grades,id',

            'stream_name' => 'required|string|max:50',

        ]);

        $stream = Stream::create([

            'id' => (string) Str::uuid(),

            'school_id' => $request->school_id,

            'grade_id' => $request->grade_id,

            'stream_name' => $request->stream_name,

            'active' => true,

            'created_at' => now(),

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Stream created successfully',

            'data' => new StreamResource(

                Stream::with([
                    'grade',
                    'school'
                ])->find($stream->id)

            )

        ], 201);
    }

    /**
     * Update Stream
     */
    public function update(Request $request, $id)
    {
        $stream = Stream::find($id);

        if (!$stream) {

            return response()->json([

                'success' => false,

                'message' => 'Stream not found'

            ], 404);
        }

        $request->validate([

            'stream_name' => 'sometimes|string|max:50',

            'active' => 'sometimes|boolean',

        ]);

        $stream->update(

            $request->except([

                'id',

                'school_id',

                'grade_id',

                'created_at'

            ])

        );

        return response()->json([

            'success' => true,

            'message' => 'Stream updated successfully',

            'data' => new StreamResource(

                Stream::with([
                    'grade',
                    'school'
                ])->find($stream->id)

            )

        ]);
    }

    /**
     * Delete Stream
     */
    public function destroy($id)
    {
        $stream = Stream::find($id);

        if (!$stream) {

            return response()->json([

                'success' => false,

                'message' => 'Stream not found'

            ], 404);
        }

        $stream->delete();

        return response()->json([

            'success' => true,

            'message' => 'Stream deleted successfully'

        ]);
    }
}
