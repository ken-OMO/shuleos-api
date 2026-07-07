<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\SchemeOfWorkResource;
use App\Models\SchemeOfWork;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SchemeOfWorkController extends BaseCrudController
{
    /**
     * Module name used in audit logs.
     */
    private const MODULE = 'Schemes Of Work';

    /**
     * Relationships loaded with scheme responses.
     */
    private const RELATIONS = [

        'school',

        'learningArea',

        'grade',

        'academicYear',

        'term',

        'lessons',

    ];

    /**
     * Display a listing of schemes of work.
     */
    public function index()
    {
        $schemes = SchemeOfWork::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->orderByDesc('created_at')
        ->paginate(20);

        return $this->success(

            SchemeOfWorkResource::collection(

                $schemes

            ),

            'Schemes of work retrieved successfully.'

        );
    }

    /**
     * Display the specified scheme of work.
     */
    public function show(string $id)
    {
        $scheme = SchemeOfWork::with(

            self::RELATIONS

        )
        ->where('is_deleted', false)
        ->find($id);

        if ($this->modelNotFound($scheme)) {

            return $this->notFound(

                'Scheme of work not found.'

            );

        }

        return $this->success(

            new SchemeOfWorkResource(

                $scheme

            ),

            'Scheme of work retrieved successfully.'

        );
    }
        /**
     * Store a newly created scheme of work.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([

            'school_id' => 'required|exists:schools,id',

            'learning_area_id' => 'required|exists:learning_areas,id',

            'grade_id' => 'required|exists:grades,id',

            'academic_year_id' => 'required|exists:academic_years,id',

            'term_id' => 'required|exists:terms,id',

            'title' => 'required|string|max:255',

            'created_by' => 'required|exists:users,id',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $scheme = SchemeOfWork::create([

                'id' => (string) Str::uuid(),

                'school_id' => $validated['school_id'],

                'learning_area_id' => $validated['learning_area_id'],

                'grade_id' => $validated['grade_id'],

                'academic_year_id' => $validated['academic_year_id'],

                'term_id' => $validated['term_id'],

                'title' => $validated['title'],

                'created_by' => $validated['created_by'],

                'active' => $validated['active'] ?? true,

                'is_deleted' => false,

                'created_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Create',

                model: $scheme,

                oldValues: null,

                newValues: $scheme->toArray(),

                description: 'Created scheme of work.'

            );

            $this->commit();

            $this->loadRelations(

                $scheme,

                self::RELATIONS

            );

            return $this->created(

                new SchemeOfWorkResource(

                    $scheme

                ),

                'Scheme of work created successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to create scheme of work.',

                [

                    'school_id' => $request->school_id,

                    'learning_area_id' => $request->learning_area_id,

                    'grade_id' => $request->grade_id,

                    'academic_year_id' => $request->academic_year_id,

                    'term_id' => $request->term_id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to create scheme of work.'

            );

        }
    }

    /**
     * Update the specified scheme of work.
     */
    public function update(Request $request, string $id)
    {
        $scheme = SchemeOfWork::find($id);

        if ($this->modelNotFound($scheme)) {

            return $this->notFound(

                'Scheme of work not found.'

            );

        }

        if ($this->isDeleted($scheme)) {

            return $this->badRequest(

                'Scheme of work has been deleted.'

            );

        }

        $validated = $request->validate([

            'title' => 'sometimes|string|max:255',

            'active' => 'sometimes|boolean',

        ]);

        $this->beginTransaction();

        try {

            $oldValues = $scheme->toArray();

            $scheme->update(

                $validated

            );

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Update',

                model: $scheme,

                oldValues: $oldValues,

                newValues: $scheme->fresh()->toArray(),

                description: 'Updated scheme of work.'

            );

            $this->commit();

            $this->loadRelations(

                $scheme,

                self::RELATIONS

            );

            return $this->success(

                new SchemeOfWorkResource(

                    $scheme

                ),

                'Scheme of work updated successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to update scheme of work.',

                [

                    'scheme_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to update scheme of work.'

            );

        }
    }

    /**
     * Soft delete the specified scheme of work.
     */
    public function destroy(Request $request, string $id)
    {
        $scheme = SchemeOfWork::find($id);

        if ($this->modelNotFound($scheme)) {

            return $this->notFound(

                'Scheme of work not found.'

            );

        }

        if ($this->isDeleted($scheme)) {

            return $this->badRequest(

                'Scheme of work has already been deleted.'

            );

        }

        $this->beginTransaction();

        try {

            $oldValues = $scheme->toArray();

            $scheme->update([

                'is_deleted' => true,

                'deleted_at' => now(),

            ]);

            $this->audit(

                request: $request,

                module: self::MODULE,

                action: 'Delete',

                model: $scheme,

                oldValues: $oldValues,

                newValues: $scheme->fresh()->toArray(),

                description: 'Soft deleted scheme of work.'

            );

            $this->commit();

            return $this->success(

                null,

                'Scheme of work deleted successfully.'

            );

        } catch (\Throwable $e) {

            $this->rollback();

            $this->logError(

                'Failed to delete scheme of work.',

                [

                    'scheme_id' => $id,

                    'exception' => $e,

                ]

            );

            return $this->error(

                'Failed to delete scheme of work.'

            );

        }
    }
}
