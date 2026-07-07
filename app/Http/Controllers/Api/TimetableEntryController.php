<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableEntry;
use App\Http\Resources\TimetableEntryResource;
use Illuminate\Http\Request;

class TimetableEntryController extends Controller
{
    public function index()
    {
        return TimetableEntryResource::collection(

            TimetableEntry::orderBy('day_of_week')

            ->get()

        );
    }

    public function show($id)
    {
        return new TimetableEntryResource(

            TimetableEntry::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetableEntry::create(

            $request->all()

        );

        return new TimetableEntryResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableEntry::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableEntryResource($record);
    }

    public function destroy($id)
    {
        TimetableEntry::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
