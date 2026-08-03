<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningAreaAllocationResource;
use App\Models\LearningAreaAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LearningAreaAllocationController extends Controller
{
    /**
     * List allocations
     */
    public function index()
    {
        $allocations = LearningAreaAllocation::with([

            'school',

            'grade',

            'learningArea',

        ])
            ->orderBy(

                'created_at',

                'desc'

            )
            ->get();

        return response()->json([

            'success' => true,

            'data' => LearningAreaAllocationResource::collection(

                $allocations

            ),

        ]);
    }

    /**
     * Single allocation
     */
    public function show($id)
    {
        $allocation = LearningAreaAllocation::with([

            'school',

            'grade',

            'learningArea',

        ])
            ->find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        return response()->json([

            'success' => true,

            'data' => new LearningAreaAllocationResource(

                $allocation

            ),

        ]);
    }

    /**
     * Create allocation
     */
    public function store(
        Request $request
    ) {
        $request->validate([

            'school_id' => 'required|exists:schools,id',

            'grade_id' => 'required|exists:grades,id',

            'learning_area_id' => 'required|exists:learning_areas,id',

            'lessons_per_week' => 'required|integer|min:1|max:20',

        ]);

        $exists =

            LearningAreaAllocation::where(

                'grade_id',

                $request->grade_id

            )
                ->where(

                    'learning_area_id',

                    $request->learning_area_id

                )
                ->exists();

        if ($exists) {

            return response()->json([

                'success' => false,

                'message' => 'Learning Area already allocated to this grade',

            ], 409);
        }

        $allocation =

            LearningAreaAllocation::create([

                'id' => (string) Str::uuid(),

                'school_id' => $request->school_id,

                'grade_id' => $request->grade_id,

                'learning_area_id' => $request->learning_area_id,

                'lessons_per_week' => $request->lessons_per_week,

                'active' => true,

                'created_at' => now(),

            ]);

        return response()->json([

            'success' => true,

            'message' => 'Learning Area allocated successfully',

            'data' => new LearningAreaAllocationResource(

                LearningAreaAllocation::with([

                    'school',

                    'grade',

                    'learningArea',

                ])
                    ->find(

                        $allocation->id

                    )

            ),

        ], 201);
    }

    /**
     * Update allocation
     */
    public function update(
        Request $request,
        $id
    ) {
        $allocation =

            LearningAreaAllocation::find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        $allocation->update([

            'lessons_per_week' => $request->lessons_per_week

                ??

                $allocation->lessons_per_week,

            'active' => $request->active

                ??

                $allocation->active,

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Allocation updated successfully',

            'data' => new LearningAreaAllocationResource(

                LearningAreaAllocation::with([

                    'school',

                    'grade',

                    'learningArea',

                ])
                    ->find(

                        $allocation->id

                    )

            ),

        ]);
    }

    /**
     * Delete allocation
     */
    public function destroy($id)
    {
        $allocation =

            LearningAreaAllocation::find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        $allocation->delete();

        return response()->json([

            'success' => true,

            'message' => 'Allocation deleted successfully',

        ]);
    }
}
