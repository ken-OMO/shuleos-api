<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomTypeResource;
use App\Models\RoomType;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index()
    {
        return RoomTypeResource::collection(

            RoomType::orderBy('type_name')
                ->get()

        );
    }

    public function show($id)
    {
        return new RoomTypeResource(

            RoomType::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'type_name' => 'required|string|max:100',

            'description' => 'nullable|string',

            'active' => 'boolean',

        ]);

        $roomType = RoomType::create($validated);

        return new RoomTypeResource($roomType);
    }

    public function update(Request $request, $id)
    {
        $roomType = RoomType::findOrFail($id);

        $validated = $request->validate([

            'type_name' => 'sometimes|string|max:100',

            'description' => 'nullable|string',

            'active' => 'boolean',

        ]);

        $roomType->update($validated);

        return new RoomTypeResource($roomType);
    }

    public function destroy($id)
    {
        RoomType::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

            'message' => 'Room type deleted successfully',

        ]);
    }
}
