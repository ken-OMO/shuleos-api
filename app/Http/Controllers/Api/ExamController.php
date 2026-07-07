<?php

namespace App\Http\Controllers\Api;

use App\Models\Exam;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;

class ExamController extends Controller
{
    public function index()
    {
        return ExamResource::collection(

            Exam::with([

                'assessmentType',

                'academicYear',

                'term',

                'learningAreas',

                'results',

                'permissions',

                'meritLists',

                'reportCards',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new ExamResource(

            Exam::with([

                'assessmentType',

                'academicYear',

                'term',

                'learningAreas',

                'results',

                'permissions',

                'meritLists',

                'reportCards',

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

            'exam_name'
                => 'required|string|max:255',

            'assessment_type_id'
                => 'required|uuid',

            'academic_year_id'
                => 'required|uuid',

            'term_id'
                => 'required|uuid',

            'start_date'
                => 'required|date',

            'end_date'
                => 'required|date',

            'active'
                => 'boolean',

            'created_by'
                => 'required|uuid',

        ]);

        $validated['created_at'] = now();

        return new ExamResource(

            Exam::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $exam = Exam::findOrFail($id);

        $validated = $request->validate([

            'exam_name'
                => 'sometimes|string|max:255',

            'start_date'
                => 'sometimes|date',

            'end_date'
                => 'sometimes|date',

            'active'
                => 'sometimes|boolean',

        ]);

        $exam->update(

            $validated

        );

        return new ExamResource(

            $exam
        );
    }

    public function destroy($id)
    {
        Exam::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Exam deleted successfully'

        ]);
    }
}
