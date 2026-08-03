<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceSession;
use Illuminate\Http\Request;

class AttendanceSessionController extends Controller
{
    public function index()
    {
        return AttendanceSessionResource::collection(

            AttendanceSession::with([

                'school',

                'attendanceRecords',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new AttendanceSessionResource(

            AttendanceSession::with([

                'school',

                'attendanceRecords',

            ])->findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id' => 'required|uuid',

            'session_name' => 'required|string|max:100',

            'session_order' => 'required|integer',

            'active' => 'boolean',

        ]);

        $validated['created_at'] = now();

        return new AttendanceSessionResource(

            AttendanceSession::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    ) {
        $session = AttendanceSession::findOrFail($id);

        $validated = $request->validate([

            'session_name' => 'sometimes|string|max:100',

            'session_order' => 'sometimes|integer',

            'active' => 'sometimes|boolean',

        ]);

        $session->update(

            $validated

        );

        return new AttendanceSessionResource(

            $session

        );
    }

    public function destroy($id)
    {
        AttendanceSession::findOrFail($id)

            ->delete();

        return response()->json([

            'message' => 'Attendance session deleted successfully',

        ]);
    }
}
