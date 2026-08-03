<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinanceSettingResource;
use App\Models\FinanceSetting;
use Illuminate\Http\Request;

class FinanceSettingController extends Controller
{
    public function index()
    {
        return FinanceSettingResource::collection(

            FinanceSetting::orderBy('created_at')
                ->get()

        );
    }

    public function show($id)
    {
        return new FinanceSettingResource(

            FinanceSetting::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = FinanceSetting::create(

            $request->all()

        );

        return new FinanceSettingResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = FinanceSetting::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new FinanceSettingResource($record);
    }

    public function destroy($id)
    {
        FinanceSetting::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
