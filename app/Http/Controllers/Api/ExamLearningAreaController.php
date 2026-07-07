<?php

namespace App\Http\Controllers\Api;

use App\Models\ExamLearningArea;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamLearningAreaResource;

class ExamLearningAreaController extends Controller
{
    public function index()
    {
        return ExamLearningAreaResource::collection(

            ExamLearningArea::with([

                'exam',

                'learningArea',

                'papers',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new ExamLearningAreaResource(

            ExamLearningArea::with([

                'exam',

                'learningArea',

                'papers',

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

            'learning_area_id'
                => 'required|uuid',

            'number_of_papers'
                => 'required|integer',

            'total_marks'
                => 'required|integer',

        ]);

        $validated['created_at'] = now();

        return new ExamLearningAreaResource(

            ExamLearningArea::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $examLearningArea = ExamLearningArea::findOrFail($id);

        $validated = $request->validate([

            'number_of_papers'
                => 'sometimes|integer',

            'total_marks'
                => 'sometimes|integer',

        ]);

        $examLearningArea->update(

            $validated

        );

        return new ExamLearningAreaResource(

            $examLearningArea

        );
    }

    public function destroy($id)
    {
        ExamLearningArea::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Exam learning area deleted successfully'

        ]);
    }
}
