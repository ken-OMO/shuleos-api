<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        return PaymentResource::collection(

            Payment::orderByDesc('payment_date')

            ->get()

        );
    }

    public function show($id)
    {
        return new PaymentResource(

            Payment::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'invoice_id' => 'required|uuid',

            'amount' => 'required|numeric',

            'payment_date' => 'required|date',

        ]);

        $record = Payment::create(

            array_merge(

                [

                    'allocated_amount' => $validated['amount'],

                    'payment_status' => 'posted',

                    'reversed' => false,

                ],

                $validated

            )

        );

        return new PaymentResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = Payment::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new PaymentResource($record);
    }

    public function destroy($id)
    {
        Payment::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
