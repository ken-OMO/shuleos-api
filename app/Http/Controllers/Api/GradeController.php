<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Http\Resources\GradeResource;
use Illuminate\Support\Str;

class GradeController extends Controller
{
    /**
     * List Grades
     */
    public function index()
    {
        return response()->json([
            'success' => true,

            'data' => GradeResource::collection(

                Grade::with([
                    'school',
                    'educationLevel'
                ])

                ->orderBy('grade_order')

                ->get()

            )

        ]);
    }

    /**
     * Get Single Grade
     */
    public function show($id)
    {
        $grade = Grade::with([
            'school',
            'educationLevel'
        ])->find($id);

        if (!$grade) {

            return response()->json([
                'success' => false,
                'message' => 'Grade not found'
            ], 404);

        }

        return response()->json([

            'success' => true,

            'data' => new GradeResource($grade)

        ]);
    }

    /**
     * Create Grade
     */
    public function store(Request $request)
    {
        $request->validate([

            'school_id' => 'required|exists:schools,id',

            'education_level_id' =>
                'required|exists:education_levels,id',

            'grade_name' => 'required',

            'grade_order' => 'required|integer',

        ]);

        $grade = Grade::create([

            'id' => (string) Str::uuid(),

            'school_id' => $request->school_id,

            'education_level_id' =>
                $request->education_level_id,

            'grade_name' => $request->grade_name,

            'grade_order' => $request->grade_order,

            'active' => true,

            'created_at' => now(),

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Grade created successfully',

            'data' => new GradeResource(

                Grade::with([
                    'school',
                    'educationLevel'
                ])

                ->find($grade->id)

            )

        ], 201);
    }

    /**
     * Update Grade
     */
    public function update(Request $request, $id)
    {
        $grade = Grade::find($id);

        if (!$grade) {

            return response()->json([

                'success' => false,

                'message' => 'Grade not found'

            ], 404);

        }

        $grade->update(

            $request->except([

                'id',

                'school_id',

                'education_level_id'

            ])

        );

        return response()->json([

            'success' => true,

            'message' => 'Grade updated successfully',

            'data' => new GradeResource(

                Grade::with([
                    'school',
                    'educationLevel'
                ])

                ->find($grade->id)

            )

        ]);
    }

    /**
     * Delete Grade
     */
    public function destroy($id)
    {
        $grade = Grade::find($id);

        if (!$grade) {

            return response()->json([

                'success' => false,

                'message' => 'Grade not found'

            ], 404);

        }

        $grade->delete();

        return response()->json([

            'success' => true,

            'message' => 'Grade deleted successfully'

        ]);
    }
}
