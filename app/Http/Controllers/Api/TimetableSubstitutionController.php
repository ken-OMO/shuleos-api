<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableSubstitutionResource;
use App\Models\TimetableSubstitution;
use Illuminate\Http\Request;

class TimetableSubstitutionController extends Controller
{
    public function index()
    {
        return TimetableSubstitutionResource::collection(

            TimetableSubstitution::orderByDesc('substitution_date')
                ->get()

        );
    }

    public function show($id)
    {
        return new TimetableSubstitutionResource(

            TimetableSubstitution::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetableSubstitution::create(

            $request->all()

        );

        return new TimetableSubstitutionResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableSubstitution::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableSubstitutionResource($record);
    }

    public function destroy($id)
    {
        TimetableSubstitution::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
