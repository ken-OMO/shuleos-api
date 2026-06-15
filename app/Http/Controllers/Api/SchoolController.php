<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\School;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    /**
     * Get all schools
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => School::where('is_deleted', false)
                ->orderBy('school_name')
                ->get()
        ]);
    }

    /**
     * Get single school
     */
    public function show($id)
    {
        $school = School::find($id);

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'School not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $school
        ]);
    }

    /**
     * Create school
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'school_code' => 'required|string|max:50|unique:schools,school_code',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'county' => 'nullable|string|max:100',
        ]);

        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => $validated['school_name'],
            'school_code' => $validated['school_code'],
            'email' => $request->email,
            'phone' => $request->phone,
            'county' => $request->county,
            'sub_county' => $request->sub_county,
            'postal_address' => $request->postal_address,
            'physical_address' => $request->physical_address,
            'school_type' => $request->school_type,
            'ownership' => $request->ownership,
            'registration_number' => $request->registration_number,
            'kra_pin' => $request->kra_pin,
            'website' => $request->website,
            'active' => true,
            'is_deleted' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'School created successfully',
            'data' => $school
        ], 201);
    }

    /**
     * Update school
     */
    public function update(Request $request, $id)
    {
        $school = School::find($id);

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'School not found'
            ], 404);
        }

        $school->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'School updated successfully',
            'data' => $school
        ]);
    }

    /**
     * Soft delete school
     */
    public function destroy($id)
    {
        $school = School::find($id);

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'School not found'
            ], 404);
        }

        $school->update([
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'School deleted successfully'
        ]);
    }
}
