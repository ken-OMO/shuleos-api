<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetablePublication;
use App\Http\Resources\TimetablePublicationResource;
use Illuminate\Http\Request;

class TimetablePublicationController extends Controller
{
    public function index()
    {
        return TimetablePublicationResource::collection(

            TimetablePublication::orderByDesc('created_at')

            ->get()

        );
    }

    public function show($id)
    {
        return new TimetablePublicationResource(

            TimetablePublication::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetablePublication::create(

            $request->all()

        );

        return new TimetablePublicationResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetablePublication::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetablePublicationResource($record);
    }

    public function destroy($id)
    {
        TimetablePublication::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
