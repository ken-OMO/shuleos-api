<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportCardResource;
use App\Models\ReportCard;
use Illuminate\Http\Request;

class ReportCardController extends Controller
{
    public function index()
    {
        return ReportCardResource::collection(

            ReportCard::with([

                'school',

                'learner',

                'exam',

                'academicYear',

                'term',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new ReportCardResource(

            ReportCard::with([

                'school',

                'learner',

                'exam',

                'academicYear',

                'term',

            ])->findOrFail($id)

        );
    }

    public function store(
        Request $request
    ) {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'exam_id' => 'required|uuid',

            'academic_year_id' => 'required|uuid',

            'term_id' => 'required|uuid',

            'overall_score' => 'required|numeric',

            'overall_grade' => 'required|string|max:10',

            'total_points' => 'required|numeric',

            'stream_position' => 'required|integer',

            'grade_position' => 'required|integer',

            'school_position' => 'required|integer',

            'total_learners' => 'required|integer',

            'attendance_percentage' => 'required|numeric',

            'class_teacher_comment' => 'nullable|string',

            'principal_comment' => 'nullable|string',

            'pathway_recommendation' => 'nullable|string',

        ]);

        $validated['generated_at'] = now();

        return new ReportCardResource(

            ReportCard::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    ) {
        $reportCard = ReportCard::findOrFail($id);

        $validated = $request->validate([

            'overall_score' => 'sometimes|numeric',

            'overall_grade' => 'sometimes|string|max:10',

            'total_points' => 'sometimes|numeric',

            'stream_position' => 'sometimes|integer',

            'grade_position' => 'sometimes|integer',

            'school_position' => 'sometimes|integer',

            'total_learners' => 'sometimes|integer',

            'attendance_percentage' => 'sometimes|numeric',

            'class_teacher_comment' => 'sometimes|string',

            'principal_comment' => 'sometimes|string',

            'pathway_recommendation' => 'sometimes|string',

        ]);

        $reportCard->update(

            $validated

        );

        return new ReportCardResource(

            $reportCard

        );
    }

    public function destroy($id)
    {
        ReportCard::findOrFail($id)

            ->delete();

        return response()->json([

            'message' => 'Report card deleted successfully',

        ]);
    }
}
