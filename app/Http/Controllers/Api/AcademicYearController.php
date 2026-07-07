<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Http\Resources\AcademicYearResource;

use App\Models\AcademicYear;

use Illuminate\Http\Request;

use Illuminate\Support\Str;

class AcademicYearController extends Controller
{
    public function index()
    {
        $years = AcademicYear::with([

            'school',

            'terms',

            'academicWeeks',

        ])

        ->orderBy('year_name')

        ->get();

        return response()->json([

            'success' => true,

            'data' => AcademicYearResource::collection(

                $years

            ),

        ]);
    }

    public function show($id)
    {
        $year = AcademicYear::with([

            'school',

            'terms',

            'academicWeeks',

        ])

        ->findOrFail($id);

        return response()->json([

            'success' => true,

            'data' => new AcademicYearResource(

                $year

            ),

        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'year_name' => 'required|string|max:50',

            'start_date' => 'required|date',

            'end_date' => 'required|date',

            'active' => 'boolean',

        ]);

        $year = AcademicYear::create([

            'id' => Str::uuid(),

            ...$validated,

            'active' => $validated['active'] ?? true,

            'created_at' => now(),

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Academic year created.',

            'data' => new AcademicYearResource(

                $year

            ),

        ],201);
    }

    public function update(Request $request,$id)
    {
        $year = AcademicYear::findOrFail($id);

        $validated = $request->validate([

            'year_name' => 'sometimes|string|max:50',

            'start_date' => 'sometimes|date',

            'end_date' => 'sometimes|date',

            'active' => 'sometimes|boolean',

        ]);

        $year->update($validated);

        return response()->json([

            'success' => true,

            'message' => 'Academic year updated.',

            'data' => new AcademicYearResource(

                $year

            ),

        ]);
    }

    public function destroy($id)
    {
        AcademicYear::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

            'message' => 'Academic year deleted.',

        ]);
    }
}
