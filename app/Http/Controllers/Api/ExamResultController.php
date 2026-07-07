<?php

namespace App\Http\Controllers\Api;

use App\Models\ExamResult;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResultResource;

class ExamResultController extends Controller
{
    public function index()
    {
        return ExamResultResource::collection(

            ExamResult::with([

                'exam',

                'learner',

                'learningArea',

                'paper',

                'enteredBy',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new ExamResultResource(

            ExamResult::with([

                'exam',

                'learner',

                'learningArea',

                'paper',

                'enteredBy',

            ])->findOrFail($id)

        );
    }

    public function store(
        Request $request
    )
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'exam_id'
                => 'required|uuid',

            'learner_id'
                => 'required|uuid',

            'learning_area_id'
                => 'required|uuid',

            'paper_id'
                => 'required|uuid',

            'marks'
                => 'required|numeric',

            'entered_by'
                => 'required|uuid',

        ]);

        $validated['created_at'] = now();

        return new ExamResultResource(

            ExamResult::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $result = ExamResult::findOrFail($id);

        $validated = $request->validate([

            'marks'
                => 'sometimes|numeric',

        ]);

        $result->update(

            $validated

        );

        return new ExamResultResource(

            $result

        );
    }

    public function destroy($id)
    {
        ExamResult::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Exam result deleted successfully'

        ]);
    }
}
