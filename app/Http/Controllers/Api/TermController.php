<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TermResource;
use App\Models\AcademicYear;
use App\Models\Term;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            'end_date' => 'required|date|after:start_date',

            'active' => 'boolean',

        ]);

        if (
            ! AcademicYear::query()
                ->whereKey(
                    $validated['academic_year_id']
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'academic_year_id' => [
                    'The selected academic year is invalid.',
                ],
            ]);
        }

        if (
            Term::query()
                ->where(
                    'academic_year_id',
                    $validated['academic_year_id']
                )
                ->where(
                    'term_name',
                    $validated['term_name']
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'term_name' => [
                    'A term with this name already exists for this academic year.',
                ],
            ]);
        }

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

        $effectiveStartDate = array_key_exists(
            'start_date',
            $validated
        )
            ? Carbon::parse(
                $validated['start_date']
            )
            : $term->start_date;

        $effectiveEndDate = array_key_exists(
            'end_date',
            $validated
        )
            ? Carbon::parse(
                $validated['end_date']
            )
            : $term->end_date;

        if (
            $effectiveStartDate
            && $effectiveEndDate
            && $effectiveEndDate->lte(
                $effectiveStartDate
            )
        ) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'The end date must be after the start date.',
                ],
            ]);
        }

        if (
            array_key_exists(
                'term_name',
                $validated
            )
            && Term::query()
                ->where(
                    'academic_year_id',
                    $term->academic_year_id
                )
                ->where(
                    'term_name',
                    $validated['term_name']
                )
                ->where(
                    $term->getKeyName(),
                    '!=',
                    $term->getKey()
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'term_name' => [
                    'A term with this name already exists for this academic year.',
                ],
            ]);
        }

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
