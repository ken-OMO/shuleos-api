<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicWeekResource;
use App\Models\AcademicWeek;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AcademicWeekController extends Controller
{
    public function index()
    {
        $weeks = AcademicWeek::with([

            'school',

            'academicYear',

            'term',

        ])
            ->orderBy('week_number')
            ->get();

        return response()->json([

            'success' => true,

            'data' => AcademicWeekResource::collection(

                $weeks

            ),

        ]);
    }

    public function show($id)
    {
        $week = AcademicWeek::with([

            'school',

            'academicYear',

            'term',

        ])
            ->findOrFail($id);

        return response()->json([

            'success' => true,

            'data' => new AcademicWeekResource(

                $week

            ),

        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'academic_year_id' => 'required|uuid',

            'term_id' => 'required|uuid',

            'week_number' => 'required|integer',

            'start_date' => 'required|date',

            'end_date' => 'required|date',

            'active' => 'boolean',

        ]);

        $week = AcademicWeek::create([

            'id' => Str::uuid(),

            ...$validated,

            'active' => $validated['active'] ?? true,

            'created_at' => now(),

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Academic week created.',

            'data' => new AcademicWeekResource(

                $week

            ),

        ], 201);
    }

    public function update(Request $request, $id)
    {
        $week = AcademicWeek::findOrFail($id);

        $validated = $request->validate([

            'week_number' => 'sometimes|integer',

            'start_date' => 'sometimes|date',

            'end_date' => 'sometimes|date',

            'active' => 'sometimes|boolean',

        ]);

        $week->update($validated);

        return response()->json([

            'success' => true,

            'message' => 'Academic week updated.',

            'data' => new AcademicWeekResource(

                $week

            ),

        ]);
    }

    public function destroy($id)
    {
        AcademicWeek::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

            'message' => 'Academic week deleted.',

        ]);
    }
}
