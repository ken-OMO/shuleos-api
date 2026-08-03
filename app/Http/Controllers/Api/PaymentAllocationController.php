<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentAllocationResource;
use App\Models\PaymentAllocation;
use Illuminate\Http\Request;

class PaymentAllocationController extends Controller
{
    public function index()
    {
        return PaymentAllocationResource::collection(

            PaymentAllocation::orderByDesc('created_at')
                ->get()

        );
    }

    public function show($id)
    {
        return new PaymentAllocationResource(

            PaymentAllocation::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'payment_id' => 'required|uuid',

            'invoice_id' => 'required|uuid',

            'allocated_amount' => 'required|numeric',

        ]);

        $record = PaymentAllocation::create(

            $validated

        );

        return new PaymentAllocationResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = PaymentAllocation::findOrFail($id);

        $validated = $request->validate([

            'allocated_amount' => 'sometimes|numeric',

            'created_by' => 'sometimes|uuid',

        ]);

        $record->update(

            $validated

        );

        return new PaymentAllocationResource($record);
    }

    public function destroy($id)
    {
        PaymentAllocation::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
