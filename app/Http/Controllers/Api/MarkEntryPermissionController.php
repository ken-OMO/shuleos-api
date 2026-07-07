<?php

namespace App\Http\Controllers\Api;

use App\Models\MarkEntryPermission;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\MarkEntryPermissionResource;

class MarkEntryPermissionController extends Controller
{
    public function index()
    {
        return MarkEntryPermissionResource::collection(

            MarkEntryPermission::with([

                'exam',

            ])->paginate(20)

        );
    }

    public function show($id)
    {
        return new MarkEntryPermissionResource(

            MarkEntryPermission::with([

                'exam',

            ])->findOrFail($id)

        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([

            'id' => 'required|uuid',

            'exam_id' => 'required|uuid',

            'role_name'
                => 'required|string|max:255',

            'active'
                => 'boolean',

        ]);

        $validated['created_at'] = now();

        return new MarkEntryPermissionResource(

            MarkEntryPermission::create(

                $validated

            )

        );
    }

    public function update(
        Request $request,
        $id
    )
    {
        $permission = MarkEntryPermission::findOrFail($id);

        $validated = $request->validate([

            'role_name'
                => 'sometimes|string|max:255',

            'active'
                => 'sometimes|boolean',

        ]);

        $permission->update(

            $validated

        );

        return new MarkEntryPermissionResource(

            $permission

        );
    }

    public function destroy($id)
    {
        MarkEntryPermission::findOrFail($id)

            ->delete();

        return response()->json([

            'message'

            => 'Mark entry permission deleted successfully'

        ]);
    }
}
