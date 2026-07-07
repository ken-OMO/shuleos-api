<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Room;

use App\Http\Resources\RoomResource;

use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index()
    {
        return RoomResource::collection(

            Room::orderBy('room_name')

            ->get()

        );
    }

    public function show($id)
    {
        return new RoomResource(

            Room::findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|uuid',

            'room_type_id' => 'required|uuid',

            'room_name' => 'required|string|max:255',

            'room_code' => 'nullable|string|max:50',

            'block_name' => 'nullable|string|max:100',

            'floor_number' => 'nullable|integer|min:0',

            'capacity' => 'required|integer|min:1',

            'active' => 'boolean',

            'created_by' => 'required|uuid',

        ]);

        $room = Room::create($validated);

        return new RoomResource($room);
    }

    public function update(Request $request, $id)
    {
        $room = Room::findOrFail($id);

        $validated = $request->validate([

            'room_type_id' => 'sometimes|uuid',

            'room_name' => 'sometimes|string|max:255',

            'room_code' => 'nullable|string|max:50',

            'block_name' => 'nullable|string|max:100',

            'floor_number' => 'nullable|integer|min:0',

            'capacity' => 'sometimes|integer|min:1',

            'active' => 'boolean',

        ]);

        $room->update($validated);

        return new RoomResource($room);
    }

    public function destroy($id)
    {
        Room::findOrFail($id)

            ->delete();

        return response()->json([

            'success' => true,

            'message' => 'Room deleted successfully',

        ]);
    }
}
