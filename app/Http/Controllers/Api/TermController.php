<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TermResource;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TermController extends Controller
{
    public function index()
    {
        $terms = Term::with([

            'school',

            'academicYear',

            'academicWeeks',

        ])
            ->orderBy('term_name')
            ->get();

        return response()->json([

            'success' => true,

            'data' => TermResource::collection(

                $terms

            ),

        ]);
    }

    public function show($id)
    {
        $term = Term::with([

            'school',

            'academicYear',

            'academicWeeks',

        ])
            ->findOrFail($id);

        return response()->json([

            'success' => true,

            'data' => new TermResource(

                $term

            ),

        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'academic_year_id' => 'required|uuid',

            'term_name' => 'required|string|max:50',

            'start_date' => 'required|date',

            'end_date' => 'required|date',

            'active' => 'boolean',

        ]);

        $term = Term::create([

            'id' => Str::uuid(),

            ...$validated,

            'active' => $validated['active'] ?? true,

            'created_at' => now(),

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Term created.',

            'data' => new TermResource(

                $term

            ),

        ], 201);
    }

    public function update(Request $request, $id)
    {
        $term = Term::findOrFail($id);

        $validated = $request->validate([

            'term_name' => 'sometimes|string|max:50',

            'start_date' => 'sometimes|date',

            'end_date' => 'sometimes|date',

            'active' => 'sometimes|boolean',

        ]);

        $term->update($validated);

        return response()->json([

            'success' => true,

            'message' => 'Term updated.',

            'data' => new TermResource(

                $term

            ),

        ]);
    }

    public function destroy($id)
    {
        Term::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

            'message' => 'Term deleted.',

        ]);
    }
}
