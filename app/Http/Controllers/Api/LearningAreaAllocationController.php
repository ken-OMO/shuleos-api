<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningAreaAllocationResource;
use App\Models\LearningAreaAllocation;
use App\Services\School\SchoolBusinessWriteAccess;
use App\Services\Teaching\TeachingWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LearningAreaAllocationController extends Controller
{
    public function workspaceOptions(Request $request, TeachingWorkspaceAccess $scope)
    {
        $schoolId = $scope->school($request);

        $areas = DB::table('learning_areas')->where('active', true)
            ->orderBy('learning_area_name')->orderBy('id')->get(['id', 'learning_area_name']);
        $catalog = $areas->keyBy(fn ($area) => Str::lower(trim($area->learning_area_name)));
        $suggestions = collect(config('learning_area_suggestions'))->map(function ($choices) use ($catalog) {
            $matched = collect($choices)->map(fn ($names) => collect($names)
                ->map(fn ($name) => $catalog->get(Str::lower($name)))
                ->first(fn ($area) => $area !== null))->filter();

            // Use the general religious area only for catalogs without separate offerings.
            $hasReligiousOptions = $matched->contains(fn ($area) => in_array(Str::lower($area->learning_area_name), [
                'christian religious education', 'islamic religious education', 'hindu religious education', 'cre', 'ire', 'hre',
            ], true));

            return $matched->reject(fn ($area) => $hasReligiousOptions && Str::lower($area->learning_area_name) === 'religious activities')
                ->pluck('id')->unique()->values();
        });

        return response()->json(['success' => true, 'data' => [
            'grades' => DB::table('grades')->leftJoin('education_levels', 'education_levels.id', '=', 'grades.education_level_id')
                ->where('grades.school_id', $schoolId)->where('grades.active', true)->orderBy('grades.grade_order')
                ->get(['grades.id', 'grades.grade_name', 'grades.grade_number', 'education_levels.level_name']),
            'areas' => $areas,
            'suggestions' => $suggestions,
            'allocations' => DB::table('learning_area_allocations')->where('school_id', $schoolId)
                ->orderBy('id')->get(['id', 'grade_id', 'learning_area_id', 'lessons_per_week', 'active']),
        ]]);
    }

    public function workspaceStore(Request $request, TeachingWorkspaceAccess $scope, SchoolBusinessWriteAccess $access)
    {
        return $access->run($scope->school($request), function () use ($request, $scope) {
            $scope->school($request);

            return $this->store($request);
        });
    }

    /**
     * List allocations
     */
    public function index()
    {
        $allocations = LearningAreaAllocation::with([

            'school',

            'grade',

            'learningArea',

        ])
            ->orderBy(

                'created_at',

                'desc'

            )
            ->get();

        return response()->json([

            'success' => true,

            'data' => LearningAreaAllocationResource::collection(

                $allocations

            ),

        ]);
    }

    /**
     * Single allocation
     */
    public function show($id)
    {
        $allocation = LearningAreaAllocation::with([

            'school',

            'grade',

            'learningArea',

        ])
            ->find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        return response()->json([

            'success' => true,

            'data' => new LearningAreaAllocationResource(

                $allocation

            ),

        ]);
    }

    /**
     * Create allocation
     */
    public function store(
        Request $request
    ) {
        $schoolId = auth()->user()?->school_id;
        $tenantId = $request->attributes->get('tenant_school_id');

        abort_unless(
            $schoolId && $tenantId && (string) $schoolId === (string) $tenantId,
            403,
            'Authenticated school context is required.'
        );

        $validated = $request->validate([
            'grade_id' => [
                'required',
                'uuid',
                Rule::exists('grades', 'id')
                    ->where('school_id', $schoolId)
                    ->where('active', true),
            ],
            'learning_area_id' => [
                'required',
                'uuid',
                Rule::exists('learning_areas', 'id')
                    ->where('active', true),
            ],
            'lessons_per_week' => 'required|integer|min:1|max:20',
        ]);

        $exists =

            LearningAreaAllocation::where(

                'grade_id',

                $request->grade_id

            )
                ->where(

                    'learning_area_id',

                    $request->learning_area_id

                )
                ->exists();

        if ($exists) {

            return response()->json([

                'success' => false,

                'message' => 'Learning Area already allocated to this grade',

            ], 409);
        }

        $allocation =

            LearningAreaAllocation::create([

                'id' => (string) Str::uuid(),

                'school_id' => $schoolId,

                'grade_id' => $request->grade_id,

                'learning_area_id' => $request->learning_area_id,

                'lessons_per_week' => $request->lessons_per_week,

                'active' => true,

                'created_at' => now(),

            ]);

        return response()->json([

            'success' => true,

            'message' => 'Learning Area allocated successfully',

            'data' => new LearningAreaAllocationResource(

                LearningAreaAllocation::with([

                    'school',

                    'grade',

                    'learningArea',

                ])
                    ->find(

                        $allocation->id

                    )

            ),

        ], 201);
    }

    /**
     * Update allocation
     */
    public function update(
        Request $request,
        $id
    ) {
        $allocation =

            LearningAreaAllocation::find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        $validated = $request->validate([
            'lessons_per_week' => 'sometimes|required|integer|min:1|max:20',
            'active' => 'sometimes|required|boolean',
        ]);

        $allocation->update([

            'lessons_per_week' => $validated['lessons_per_week']

                ??

                $allocation->lessons_per_week,

            'active' => $validated['active']

                ??

                $allocation->active,

        ]);

        return response()->json([

            'success' => true,

            'message' => 'Allocation updated successfully',

            'data' => new LearningAreaAllocationResource(

                LearningAreaAllocation::with([

                    'school',

                    'grade',

                    'learningArea',

                ])
                    ->find(

                        $allocation->id

                    )

            ),

        ]);
    }

    /**
     * Delete allocation
     */
    public function destroy($id)
    {
        $allocation =

            LearningAreaAllocation::find($id);

        if (! $allocation) {

            return response()->json([

                'success' => false,

                'message' => 'Allocation not found',

            ], 404);
        }

        $allocation->delete();

        return response()->json([

            'success' => true,

            'message' => 'Allocation deleted successfully',

        ]);
    }
}
