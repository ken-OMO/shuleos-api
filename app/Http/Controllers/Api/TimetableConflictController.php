<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableConflictResource;
use App\Models\TimetableConflict;
use Illuminate\Http\Request;

class TimetableConflictController extends Controller
{
    public function index()
    {
        return TimetableConflictResource::collection(

            TimetableConflict::orderBy('created_at')
                ->get()

        );
    }

    public function show($id)
    {
        return new TimetableConflictResource(

            TimetableConflict::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetableConflict::create(

            $request->all()

        );

        return new TimetableConflictResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableConflict::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableConflictResource($record);
    }

    public function destroy($id)
    {
        TimetableConflict::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
