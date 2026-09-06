<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\GradeResource;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GradeController extends BaseCrudController
{
    private const MODULE = 'Grades';

    private const RELATIONS = [
        'educationLevel',
    ];

    public function index(Request $request)
    {
        $grades = Grade::query()
            ->where(
                'school_id',
                $this->schoolId($request)
            )
            ->with(self::RELATIONS)
            ->orderBy('grade_order')
            ->get();

        return $this->success(
            GradeResource::collection($grades),
            'Grades retrieved successfully.'
        );
    }

    public function show(
        Request $request,
        string $id
    ) {
        $grade = $this->gradeQuery($request)
            ->with(self::RELATIONS)
            ->find($id);

        if (! $grade) {
            return $this->notFound(
                'Grade not found.'
            );
        }

        return $this->success(
            new GradeResource($grade),
            'Grade retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'education_level_id' => 'required|uuid|exists:education_levels,id',
            'grade_name' => 'required|string|max:50',
            'grade_order' => 'required|integer|min:1',
        ]);

        $schoolId = $this->schoolId($request);

        if (
            Grade::query()
                ->where('school_id', $schoolId)
                ->where(
                    'grade_name',
                    $validated['grade_name']
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'grade_name' => [
                    'A grade with this name already exists.',
                ],
            ]);
        }

        try {
            $grade = DB::transaction(
                function () use (
                    $request,
                    $validated,
                    $schoolId
                ) {
                    $grade = Grade::create([
                        'id' => (string) Str::uuid(),
                        'school_id' => $schoolId,
                        'education_level_id' => $validated['education_level_id'],
                        'grade_name' => $validated['grade_name'],
                        'grade_order' => $validated['grade_order'],
                        'active' => true,
                        'created_at' => now(),
                    ]);

                    $this->audit(
                        request: $request,
                        module: self::MODULE,
                        action: 'Create',
                        model: $grade,
                        oldValues: null,
                        newValues: $grade->toArray(),
                        description: 'Created grade.'
                    );

                    return $grade;
                }
            );

            $grade->load(self::RELATIONS);

            return $this->created(
                new GradeResource($grade),
                'Grade created successfully.'
            );
        } catch (\Throwable $e) {
            $this->logError(
                'Failed to create grade.',
                [
                    'school_id' => $schoolId,
                    'education_level_id' => $validated['education_level_id'],
                    'grade_name' => $validated['grade_name'],
                    'exception' => $e,
                ]
            );

            return $this->error(
                'Failed to create grade.'
            );
        }
    }

    public function update(
        Request $request,
        string $id
    ) {
        $grade = $this->gradeQuery($request)
            ->find($id);

        if (! $grade) {
            return $this->notFound(
                'Grade not found.'
            );
        }

        $validated = $request->validate([
            'grade_name' => 'sometimes|string|max:50',
            'grade_order' => 'sometimes|integer|min:1',
            'active' => 'sometimes|boolean',
        ]);

        if (
            array_key_exists(
                'grade_name',
                $validated
            )
            && Grade::query()
                ->where(
                    'school_id',
                    $this->schoolId($request)
                )
                ->where(
                    'grade_name',
                    $validated['grade_name']
                )
                ->whereKeyNot($grade->getKey())
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'grade_name' => [
                    'A grade with this name already exists.',
                ],
            ]);
        }

        try {
            DB::transaction(
                function () use (
                    $request,
                    $grade,
                    $validated
                ) {
                    $oldValues = $grade->toArray();

                    $grade->update($validated);

                    $this->audit(
                        request: $request,
                        module: self::MODULE,
                        action: 'Update',
                        model: $grade,
                        oldValues: $oldValues,
                        newValues: $grade->fresh()->toArray(),
                        description: 'Updated grade.'
                    );
                }
            );

            $grade->refresh();
            $grade->load(self::RELATIONS);

            return $this->success(
                new GradeResource($grade),
                'Grade updated successfully.'
            );
        } catch (\Throwable $e) {
            $this->logError(
                'Failed to update grade.',
                [
                    'grade_id' => $id,
                    'exception' => $e,
                ]
            );

            return $this->error(
                'Failed to update grade.'
            );
        }
    }

    public function destroy(
        Request $request,
        string $id
    ) {
        $grade = $this->gradeQuery($request)
            ->find($id);

        if (! $grade) {
            return $this->notFound(
                'Grade not found.'
            );
        }

        try {
            DB::transaction(
                function () use (
                    $request,
                    $grade
                ) {
                    $oldValues = $grade->toArray();

                    $this->audit(
                        request: $request,
                        module: self::MODULE,
                        action: 'Delete',
                        model: $grade,
                        oldValues: $oldValues,
                        newValues: null,
                        description: 'Deleted grade.'
                    );

                    $grade->delete();
                }
            );

            return $this->success(
                null,
                'Grade deleted successfully.'
            );
        } catch (\Throwable $e) {
            $this->logError(
                'Failed to delete grade.',
                [
                    'grade_id' => $id,
                    'exception' => $e,
                ]
            );

            return $this->error(
                'Failed to delete grade.'
            );
        }
    }

    private function gradeQuery(
        Request $request
    ) {
        return Grade::query()
            ->where(
                'school_id',
                $this->schoolId($request)
            );
    }

    private function schoolId(
        Request $request
    ): string {
        $schoolId =
            $request->attributes->get(
                'tenant_school_id'
            )
            ?? auth()->user()?->school_id;

        abort_unless(
            $schoolId,
            403,
            'School context not found.'
        );

        return (string) $schoolId;
    }
}
