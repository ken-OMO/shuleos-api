<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceStatus;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceStatusResource;

class AttendanceStatusController extends Controller
{
    public function index()
    {
        return AttendanceStatusResource::collection(

            AttendanceStatus::with(

                'attendanceRecords'

            )->paginate(20)

        );
    }

    public function show($id)
    {
        return new AttendanceStatusResource(

            AttendanceStatus::with(

                'attendanceRecords'

            )->findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'status_name'
                => 'required|string|max:100',

            'status_code'
                => 'required|string|max:10',

            'active'
                => 'boolean',

        ]);

        $validated['created_at'] = now();

        return new AttendanceStatusResource(

            AttendanceStatus::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $status = AttendanceStatus::findOrFail($id);

        $validated = $request->validate([

            'status_name'
                => 'sometimes|string|max:100',

            'status_code'
                => 'sometimes|string|max:10',

            'active'
                => 'sometimes|boolean',

        ]);

        $status->update(

            $validated

        );

        return new AttendanceStatusResource(

            $status

        );
    }

    public function destroy($id)
    {
        AttendanceStatus::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Attendance status deleted successfully'

        ]);
    }
}
