<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableGenerationRun;
use App\Http\Resources\TimetableGenerationRunResource;
use Illuminate\Http\Request;

class TimetableGenerationRunController extends Controller
{
    public function index()
    {
        return TimetableGenerationRunResource::collection(

            TimetableGenerationRun::orderByDesc('created_at')

            ->get()

        );
    }

    public function show($id)
    {
        return new TimetableGenerationRunResource(

            TimetableGenerationRun::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetableGenerationRun::create(

            $request->all()

        );

        return new TimetableGenerationRunResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableGenerationRun::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetableGenerationRunResource($record);
    }

    public function destroy($id)
    {
        TimetableGenerationRun::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
