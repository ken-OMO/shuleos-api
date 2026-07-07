<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableConstraint;
use App\Http\Resources\TimetableConstraintResource;
use Illuminate\Http\Request;

class TimetableConstraintController extends Controller
{
    public function index()
    {
        return TimetableConstraintResource::collection(

            TimetableConstraint::orderBy('constraint_name')

            ->get()

        );
    }

    public function show($id)
    {
        return new TimetableConstraintResource(

            TimetableConstraint::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetableConstraint::create(

            $request->all()

        );

        return new TimetableConstraintResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableConstraint::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableConstraintResource($record);
    }

    public function destroy($id)
    {
        TimetableConstraint::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
