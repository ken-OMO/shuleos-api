<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Http\Resources\PaymentMethodResource;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return PaymentMethodResource::collection(

            PaymentMethod::orderBy('method_name')

            ->get()

        );
    }

    public function show($id)
    {
        return new PaymentMethodResource(

            PaymentMethod::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $record = PaymentMethod::create(

            $request->all()

        );

        return new PaymentMethodResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = PaymentMethod::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new PaymentMethodResource($record);
    }

    public function destroy($id)
    {
        PaymentMethod::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
