<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function index()
    {
        return TimetableResource::collection(

            Timetable::orderBy('timetable_name')
                ->get()

        );
    }

    public function show($id)
    {
        return new TimetableResource(

            Timetable::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = Timetable::create(

            $request->all()

        );

        return new TimetableResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = Timetable::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableResource($record);
    }

    public function destroy($id)
    {
        Timetable::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
