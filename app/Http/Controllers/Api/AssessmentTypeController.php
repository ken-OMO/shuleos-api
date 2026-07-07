<?php

namespace App\Http\Controllers\Api;

use App\Models\AssessmentType;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentTypeResource;

class AssessmentTypeController extends Controller
{
    public function index()
    {
        return AssessmentTypeResource::collection(

            AssessmentType::with([

                'school',

                'exams'

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new AssessmentTypeResource(

            AssessmentType::with([

                'school',

                'exams'

            ])->findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id' => 'required|uuid',

            'assessment_type_name'
                => 'required|string|max:255',

            'active'
                => 'boolean',

        ]);

        $validated['created_at'] = now();

        return new AssessmentTypeResource(

            AssessmentType::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $assessmentType = AssessmentType::findOrFail($id);

        $validated = $request->validate([

            'assessment_type_name'
                => 'sometimes|string|max:255',

            'active'
                => 'sometimes|boolean',

        ]);

        $assessmentType->update(

            $validated

        );

        return new AssessmentTypeResource(

            $assessmentType

        );
    }

    public function destroy($id)
    {
        AssessmentType::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Assessment type deleted successfully'

        ]);
    }
}
