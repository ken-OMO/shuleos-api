<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearnerAttendanceResource;
use App\Models\LearnerAttendance;
use Illuminate\Http\Request;

class LearnerAttendanceController extends Controller
{
    public function index()
    {
        return LearnerAttendanceResource::collection(

            LearnerAttendance::with([

                'school',

                'learner',

                'grade',

                'stream',

                'attendanceSession',

                'attendanceStatus',

                'markedBy',

                'alerts',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new LearnerAttendanceResource(

            LearnerAttendance::with([

                'school',

                'learner',

                'grade',

                'stream',

                'attendanceSession',

                'attendanceStatus',

                'markedBy',

                'alerts',

            ])->findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'grade_id' => 'required|uuid',

            'stream_id' => 'required|uuid',

            'attendance_session_id' => 'required|uuid',

            'attendance_status_id' => 'required|uuid',

            'attendance_date' => 'required|date',

            'remarks' => 'nullable|string',

            'marked_by' => 'required|uuid',

        ]);

        return new LearnerAttendanceResource(

            LearnerAttendance::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    ) {
        $attendance = LearnerAttendance::findOrFail($id);

        $validated = $request->validate([

            'attendance_status_id' => 'sometimes|uuid',

            'remarks' => 'sometimes|string',

        ]);

        $attendance->update(

            $validated

        );

        return new LearnerAttendanceResource(

            $attendance

        );
    }

    public function destroy($id)
    {
        LearnerAttendance::findOrFail($id)

            ->delete();

        return response()->json([

            'message' => 'Attendance deleted successfully',

        ]);
    }
}
