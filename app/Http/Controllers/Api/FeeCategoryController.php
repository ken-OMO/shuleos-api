<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeeCategoryResource;
use App\Models\FeeCategory;
use Illuminate\Http\Request;

class FeeCategoryController extends Controller
{
    public function index()
    {
        return FeeCategoryResource::collection(

            FeeCategory::orderBy('category_name')
                ->get()

        );
    }

    public function show($id)
    {
        return new FeeCategoryResource(

            FeeCategory::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = FeeCategory::create(

            $request->all()

        );

        return new FeeCategoryResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = FeeCategory::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new FeeCategoryResource($record);
    }

    public function destroy($id)
    {
        FeeCategory::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
