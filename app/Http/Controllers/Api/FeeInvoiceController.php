<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeInvoice;
use App\Http\Resources\FeeInvoiceResource;
use Illuminate\Http\Request;

class FeeInvoiceController extends Controller
{
    public function index()
    {
        return FeeInvoiceResource::collection(

            FeeInvoice::orderBy('invoice_date')

            ->get()

        );
    }

    public function show($id)
    {
        return new FeeInvoiceResource(

            FeeInvoice::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'invoice_number' => 'required|string',

            'total_amount' => 'required|numeric',

            'invoice_date' => 'required|date',

            'due_date' => 'required|date',

        ]);

        $record = FeeInvoice::create(

            array_merge(

                [

                    'amount_paid' => 0,

                    'balance' => $validated['total_amount'],

                    'status' => 'draft',

                ],

                $validated

            )

        );

        return new FeeInvoiceResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = FeeInvoice::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new FeeInvoiceResource($record);
    }

    public function destroy($id)
    {
        $record = FeeInvoice::findOrFail($id);

        $record->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
