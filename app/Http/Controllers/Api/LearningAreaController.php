<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LearningAreaResource;
use App\Models\LearningArea;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LearningAreaController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Learning Areas';

    /**
     * Relationships loaded with learning area responses.
     */
    private const RELATIONS = [

        'educationLevels',

        'allocations',

    ];

    /**
     * Display a listing of learning areas.
     */
    public function index()
    {
        $areas = LearningArea::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->orderBy('learning_area_name')
            ->get();

        return $this->success(

            LearningAreaResource::collection(

                $areas

            ),

            'Learning areas retrieved successfully.'

        );
    }

    /**
     * Display the specified learning area.
     */
    public function show(string $id)
    {
        $area = LearningArea::with(

            self::RELATIONS

        )
            ->where('is_deleted', false)
            ->find($id);

        if ($this->modelNotFound($area)) {

            return $this->notFound(

                'Learning area not found.'

            );

        }

        return $this->success(

            new LearningAreaResource(

                $area

            ),

            'Learning area retrieved successfully.'

        );
    }

    /**
     * Store a newly created learning area.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'learning_area_name' => 'required|string|max:150',

            'short_name' => 'required|string|max:30',

            'category' => 'required|string|max:50',

            'is_core' => 'sometimes|boolean',

            'is_examined' => 'sometimes|boolean',

            'is_custom' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $area = LearningArea::create([

                'id' => (string) Str::uuid(),

                'learning_area_name' => $validated['learning_area_name'],

                'short_name' => $validated['short_name'],

                'category' => $validated['category'],

                'is_core' => $validated['is_core'] ?? true,

                'is_examined' => $validated['is_examined'] ?? true,

                'is_custom' => $validated['is_custom'] ?? true,

                'active' => true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $area,

                oldValues: null,

                newValues: $area->toArray(),

                description: 'Created learning area.'

            );

            $this->commit();

            $this->loadRelations(

                $area,

                self::RELATIONS

            );

            return $this->created(

                new LearningAreaResource(

                    $area

                ),

                'Learning area created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create learning area.',

                [

                    'learning_area_name' => $request->learning_area_name,

                    'short_name' => $request->short_name,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create learning area.'

            );

        }
    }

    /**
     * Update the specified learning area.
     */
    public function update(Request $request, string $id)
    {
        $area = LearningArea::find($id);

        if ($this->modelNotFound($area)) {

            return $this->notFound(

                'Learning area not found.'

            );

        }

        if ($this->isDeleted($area)) {

            return $this->badRequest(

                'Learning area has been deleted.'

            );

        }

        $validated = $request->validate([

            'learning_area_name' => 'sometimes|string|max:150',

            'short_name' => 'sometimes|string|max:30',

            'category' => 'sometimes|string|max:50',

            'is_core' => 'sometimes|boolean',

            'is_examined' => 'sometimes|boolean',

            'is_custom' => 'sometimes|boolean',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $area->toArray();

            $area->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $area,

                oldValues: $oldValues,

                newValues: $area->fresh()->toArray(),

                description: 'Updated learning area.'

            );

            $this->commit();

            $this->loadRelations(

                $area,

                self::RELATIONS

            );

            return $this->success(

                new LearningAreaResource(

                    $area

                ),

                'Learning area updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update learning area.',

                [

                    'learning_area_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update learning area.'

            );

        }
    }

    /**
     * Soft delete the specified learning area.
     */
    public function destroy(Request $request, string $id)
    {
        $area = LearningArea::find($id);

        if ($this->modelNotFound($area)) {

            return $this->notFound(

                'Learning area not found.'

            );

        }

        if ($this->isDeleted($area)) {

            return $this->badRequest(

                'Learning area has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $area->toArray();

            $area->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $area,

                oldValues: $oldValues,

                newValues: $area->fresh()->toArray(),

                description: 'Soft deleted learning area.'

            );

            $this->commit();

            return $this->success(

                null,

                'Learning area deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete learning area.',

                [

                    'learning_area_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete learning area.'

            );

        }
    }
}
