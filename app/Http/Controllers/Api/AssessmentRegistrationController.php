<?php

namespace App\Http\Controllers\Api;

use App\Models\AssessmentRegistration;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentRegistrationResource;

class AssessmentRegistrationController extends Controller
{
    public function index()
    {
        return AssessmentRegistrationResource::collection(

            AssessmentRegistration::with([

                'school',

                'learner',

                'creator'

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new AssessmentRegistrationResource(

            AssessmentRegistration::with([

                'school',

                'learner',

                'creator'

            ])->findOrFail($id)

        );
    }

    public function store(
        Request $request
    )
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'assessment_type'
                => 'required|string|max:255',

            'assessment_year'
                => 'required|integer',

            'candidate_number'
                => 'required|string|max:255',

            'registration_number'
                => 'required|string|max:255',

            'status'
                => 'required|string|max:255',

            'created_by'
                => 'required|uuid',

        ]);

        $validated['created_at'] = now();

        return new AssessmentRegistrationResource(

            AssessmentRegistration::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $registration = AssessmentRegistration::findOrFail($id);

        $validated = $request->validate([

            'assessment_type'
                => 'sometimes|string|max:255',

            'assessment_year'
                => 'sometimes|integer',

            'candidate_number'
                => 'sometimes|string|max:255',

            'registration_number'
                => 'sometimes|string|max:255',

            'status'
                => 'sometimes|string|max:255',

        ]);

        $registration->update(

            $validated

        );

        return new AssessmentRegistrationResource(

            $registration

        );
    }

    public function destroy($id)
    {
        AssessmentRegistration::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Assessment registration deleted successfully'

        ]);
    }
}
