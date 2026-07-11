<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableProfileResource;
use App\Models\TimetableProfile;
use Illuminate\Http\Request;

class TimetableProfileController extends Controller
{
    public function index()
    {
        return TimetableProfileResource::collection(
            TimetableProfile::orderBy('profile_name')->get()
        );
    }

    public function show($id)
    {
        return new TimetableProfileResource(
            TimetableProfile::findOrFail($id)
        );
    }

    public function store(Request $request)
    {
        $record = TimetableProfile::create(
            $request->all()
        );

        return new TimetableProfileResource($record);
    }

    public function update(Request $request, $id)
    {
        $record = TimetableProfile::findOrFail($id);

        $record->update(
            $request->all()
        );

        return new TimetableProfileResource($record);
    }

    public function destroy($id)
    {
        TimetableProfile::findOrFail($id)
            ->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
