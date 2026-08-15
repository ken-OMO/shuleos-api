<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicYearResource;
use App\Models\AcademicYear;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            'end_date' => 'required|date|after:start_date',

            'active' => 'boolean',

        ]);

        if (
            AcademicYear::query()
                ->where(
                    'year_name',
                    $validated['year_name']
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'year_name' => [
                    'An academic year with this name already exists.',
                ],
            ]);
        }

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

        ], 201);
    }

    public function update(Request $request, $id)
    {
        $year = AcademicYear::findOrFail($id);

        $validated = $request->validate([

            'year_name' => 'sometimes|string|max:50',

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
            : $year->start_date;

        $effectiveEndDate = array_key_exists(
            'end_date',
            $validated
        )
            ? Carbon::parse(
                $validated['end_date']
            )
            : $year->end_date;

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
                'year_name',
                $validated
            )
            && AcademicYear::query()
                ->where(
                    'year_name',
                    $validated['year_name']
                )
                ->where(
                    $year->getKeyName(),
                    '!=',
                    $year->getKey()
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'year_name' => [
                    'An academic year with this name already exists.',
                ],
            ]);
        }

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
