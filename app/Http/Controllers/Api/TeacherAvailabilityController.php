<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeacherAvailabilityResource;
use App\Models\TeacherAvailability;
use Illuminate\Http\Request;

class TeacherAvailabilityController extends Controller
{
    public function index()
    {
        return TeacherAvailabilityResource::collection(

            TeacherAvailability::orderBy('day_of_week')
                ->get()

        );
    }

    public function show($id)
    {
        return new TeacherAvailabilityResource(

            TeacherAvailability::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TeacherAvailability::create(

            $request->all()

        );

        return new TeacherAvailabilityResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TeacherAvailability::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TeacherAvailabilityResource($record);
    }

    public function destroy($id)
    {
        TeacherAvailability::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
