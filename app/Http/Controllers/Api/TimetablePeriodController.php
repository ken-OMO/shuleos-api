<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetablePeriod;
use App\Http\Resources\TimetablePeriodResource;
use Illuminate\Http\Request;

class TimetablePeriodController extends Controller
{
    public function index()
    {
        return TimetablePeriodResource::collection(

            TimetablePeriod::orderBy('period_order')

            ->get()

        );
    }

    public function show($id)
    {
        return new TimetablePeriodResource(

            TimetablePeriod::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = TimetablePeriod::create(

            $request->all()

        );

        return new TimetablePeriodResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetablePeriod::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new TimetablePeriodResource($record);
    }

    public function destroy($id)
    {
        TimetablePeriod::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
