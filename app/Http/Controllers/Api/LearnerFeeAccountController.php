<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearnerFeeAccountResource;
use App\Models\LearnerFeeAccount;
use Illuminate\Http\Request;

class LearnerFeeAccountController extends Controller
{
    public function index()
    {
        return LearnerFeeAccountResource::collection(

            LearnerFeeAccount::orderBy('account_number')
                ->get()

        );
    }

    public function show($id)
    {
        return new LearnerFeeAccountResource(

            LearnerFeeAccount::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'learner_id' => 'required|uuid',

            'account_number' => 'required|string',

        ]);

        $record = LearnerFeeAccount::create(

            array_merge(

                [

                    'current_balance' => 0,

                    'credit_limit' => 0,

                    'account_status' => 'active',

                    'active' => true,

                ],

                $validated

            )

        );

        return new LearnerFeeAccountResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = LearnerFeeAccount::findOrFail($id);

        $record->update(

            $request->all()

        );

        return new LearnerFeeAccountResource($record);
    }

    public function destroy($id)
    {
        $record = LearnerFeeAccount::findOrFail($id);

        $record->delete();

        return response()->json([

            'success' => true,

        ]);
    }
}
