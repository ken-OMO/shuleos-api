<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceAlert;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceAlertResource;

class AttendanceAlertController extends Controller
{
    public function index()
    {
        return AttendanceAlertResource::collection(

            AttendanceAlert::with([

                'school',

                'learner',

                'attendance',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new AttendanceAlertResource(

            AttendanceAlert::with([

                'school',

                'learner',

                'attendance',

            ])->findOrFail($id)

        );
    }

    public function store(
        Request $request
    )
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id'
                => 'required|uuid',

            'learner_id'
                => 'required|uuid',

            'attendance_id'
                => 'required|uuid',

            'parent_notified'
                => 'boolean',

            'notification_method'
                => 'nullable|string|max:100',

            'notified_at'
                => 'nullable|date',

        ]);

        $validated['created_at'] = now();

        return new AttendanceAlertResource(

            AttendanceAlert::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $alert = AttendanceAlert::findOrFail($id);

        $validated = $request->validate([

            'parent_notified'
                => 'sometimes|boolean',

            'notification_method'
                => 'sometimes|string|max:100',

            'notified_at'
                => 'sometimes|date',

        ]);

        $alert->update(

            $validated

        );

        return new AttendanceAlertResource(

            $alert

        );
    }

    public function destroy($id)
    {
        AttendanceAlert::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Attendance alert deleted successfully'

        ]);
    }
}
