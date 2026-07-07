<?php

namespace App\Http\Controllers\Api;

use App\Models\ExamPaper;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamPaperResource;

class ExamPaperController extends Controller
{
    public function index()
    {
        return ExamPaperResource::collection(

            ExamPaper::with([

                'examLearningArea',

                'results',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new ExamPaperResource(

            ExamPaper::with([

                'examLearningArea',

                'results',

            ])->findOrFail($id)

        );
    }

    public function store(
        Request $request
    )
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'exam_learning_area_id'
                => 'required|uuid',

            'paper_name'
                => 'required|string|max:255',

            'paper_number'
                => 'required|integer',

            'max_marks'
                => 'required|integer',

        ]);

        $validated['created_at'] = now();

        return new ExamPaperResource(

            ExamPaper::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $paper = ExamPaper::findOrFail($id);

        $validated = $request->validate([

            'paper_name'
                => 'sometimes|string|max:255',

            'paper_number'
                => 'sometimes|integer',

            'max_marks'
                => 'sometimes|integer',

        ]);

        $paper->update(

            $validated

        );

        return new ExamPaperResource(

            $paper

        );
    }

    public function destroy($id)
    {
        ExamPaper::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Exam paper deleted successfully'

        ]);
    }
}
