<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherConstraint;
use App\Http\Resources\TeacherConstraintResource;
use Illuminate\Http\Request;

class TeacherConstraintController extends Controller
{
    public function index()
    {
        return TeacherConstraintResource::collection(

            TeacherConstraint::orderBy('teacher_id')

            ->get()

        );
    }

    public function show($id)
    {
        return new TeacherConstraintResource(

            TeacherConstraint::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TeacherConstraint::create(

            $request->all()

        );

        return new TeacherConstraintResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TeacherConstraint::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TeacherConstraintResource($record);
    }

    public function destroy($id)
    {
        TeacherConstraint::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
