<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Teacher;
use App\Http\Resources\TeacherResource;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    /**
     * List Teachers
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => TeacherResource::collection(
                Teacher::with(['user', 'school'])
                    ->where('is_deleted', false)
                    ->orderBy('created_at', 'desc')
                    ->get()
            )
        ]);
    }

    /**
     * Get Single Teacher
     */
    public function show($id)
    {
        $teacher = Teacher::with(['user', 'school'])
            ->where('is_deleted', false)
            ->find($id);

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new TeacherResource($teacher)
        ]);
    }

    /**
     * Create Teacher
     */
    public function store(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'user_id' => 'required|exists:users,id',
            'tsc_no' => 'required|unique:teachers,tsc_no',
            'staff_no' => 'required|unique:teachers,staff_no',
        ]);

        $teacher = Teacher::create([
            'id' => (string) Str::uuid(),
            'school_id' => $request->school_id,
            'user_id' => $request->user_id,
            'tsc_no' => $request->tsc_no,
            'staff_no' => $request->staff_no,
            'gender' => $request->gender,
            'designation' => $request->designation,
            'employment_type' => $request->employment_type,
            'phone' => $request->phone,
            'email' => $request->email,
            'national_id' => $request->national_id,
            'date_joined' => $request->date_joined,
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Teacher created successfully',
            'data' => new TeacherResource(
                Teacher::with(['user', 'school'])
                    ->find($teacher->id)
            )
        ], 201);
    }

    /**
     * Update Teacher
     */
    public function update(Request $request, $id)
    {
        $teacher = Teacher::find($id);

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher not found'
            ], 404);
        }

        $request->validate([
            'tsc_no' => 'sometimes|unique:teachers,tsc_no,' . $id . ',id',
            'staff_no' => 'sometimes|unique:teachers,staff_no,' . $id . ',id',
        ]);

        $teacher->update(
            $request->except([
                'id',
                'school_id',
                'user_id'
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Teacher updated successfully',
            'data' => new TeacherResource(
                Teacher::with(['user', 'school'])
                    ->find($teacher->id)
            )
        ]);
    }

    /**
     * Soft Delete Teacher
     */
    public function destroy($id)
    {
        $teacher = Teacher::find($id);

        if (!$teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher not found'
            ], 404);
        }

        $teacher->update([
            'is_deleted' => true,
            'deleted_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Teacher deleted successfully'
        ]);
    }
}
