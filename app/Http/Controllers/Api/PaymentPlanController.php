<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentPlan;
use App\Http\Resources\PaymentPlanResource;
use Illuminate\Http\Request;

class PaymentPlanController extends Controller
{
    public function index()
    {
        return PaymentPlanResource::collection(

            PaymentPlan::orderBy('plan_name')

            ->get()

        );
    }

    public function show($id)
    {
        return new PaymentPlanResource(

            PaymentPlan::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = PaymentPlan::create(

            $request->all()

        );

        return new PaymentPlanResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = PaymentPlan::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new PaymentPlanResource($record);
    }

    public function destroy($id)
    {
        PaymentPlan::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
