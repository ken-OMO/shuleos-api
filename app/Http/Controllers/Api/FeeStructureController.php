<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeeStructureResource;
use App\Models\FeeStructure;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    public function index()
    {
        return FeeStructureResource::collection(

            FeeStructure::orderBy('created_at')
                ->get()

        );
    }

    public function show($id)
    {
        return new FeeStructureResource(

            FeeStructure::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = FeeStructure::create(

            $request->all()

        );

        return new FeeStructureResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = FeeStructure::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new FeeStructureResource($record);
    }

    public function destroy($id)
    {
        FeeStructure::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
