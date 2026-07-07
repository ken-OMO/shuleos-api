<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomConstraint;
use App\Http\Resources\RoomConstraintResource;
use Illuminate\Http\Request;

class RoomConstraintController extends Controller
{
    public function index()
    {
        return RoomConstraintResource::collection(

            RoomConstraint::orderBy('constraint_type')

            ->get()

        );
    }

    public function show($id)
    {
        return new RoomConstraintResource(

            RoomConstraint::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = RoomConstraint::create(

            $request->all()

        );

        return new RoomConstraintResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = RoomConstraint::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new RoomConstraintResource($record);
    }

    public function destroy($id)
    {
        RoomConstraint::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
