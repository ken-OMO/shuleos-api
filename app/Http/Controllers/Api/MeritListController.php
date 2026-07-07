<?php

namespace App\Http\Controllers\Api;

use App\Models\MeritList;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeritListResource;

class MeritListController extends Controller
{
    public function index()
    {
        return MeritListResource::collection(

            MeritList::with([

                'school',

                'exam',

                'learner',

                'grade',

                'stream',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new MeritListResource(

            MeritList::with([

                'school',

                'exam',

                'learner',

                'grade',

                'stream',

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

            'exam_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'grade_id' => 'required|uuid',

            'stream_id' => 'required|uuid',

            'total_score'
                => 'required|numeric',

            'total_points'
                => 'required|numeric',

            'stream_position'
                => 'required|integer',

            'grade_position'
                => 'required|integer',

            'school_position'
                => 'required|integer',

        ]);

        $validated['created_at'] = now();

        return new MeritListResource(

            MeritList::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $meritList = MeritList::findOrFail($id);

        $validated = $request->validate([

            'total_score'
                => 'sometimes|numeric',

            'total_points'
                => 'sometimes|numeric',

            'stream_position'
                => 'sometimes|integer',

            'grade_position'
                => 'sometimes|integer',

            'school_position'
                => 'sometimes|integer',

        ]);

        $meritList->update(

            $validated

        );

        return new MeritListResource(

            $meritList

        );
    }

    public function destroy($id)
    {
        MeritList::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Merit list deleted successfully'

        ]);
    }
}
