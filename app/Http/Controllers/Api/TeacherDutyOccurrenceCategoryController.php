<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\TeacherDuty\DeactivateTeacherDutyOccurrenceCategoryRequest;
use App\Http\Requests\TeacherDuty\StoreTeacherDutyOccurrenceCategoryRequest;
use App\Models\TeacherDutyOccurrenceCategory;
use App\Services\TeacherDuty\TeacherDutyOccurrenceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeacherDutyOccurrenceCategoryController extends BaseCrudController
{
    private const MODULE = 'Teacher Duty Occurrence Categories';

    public function __construct(
        private readonly TeacherDutyOccurrenceService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->teacherDutyOperation(
            fn () => $this->service->categories(
                $this->schoolId($request)
            )
        );

        return response()->json([
            'data' => $categories
                ->map(
                    fn (TeacherDutyOccurrenceCategory $category): array => $this->categoryResource($category)
                )
                ->values(),
        ]);
    }

    public function store(
        StoreTeacherDutyOccurrenceCategoryRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $category = $this->teacherDutyOperation(
            fn () => $this->service->createCustomCategory(
                $schoolId,
                (string) $validated['code'],
                (string) $validated['name'],
                isset($validated['description'])
                    ? (string) $validated['description']
                    : null,
                (int) $validated['display_order'],
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Create',
            $category,
            null,
            $this->categoryAuditValues($category),
            'Created Teacher duty occurrence category.'
        );

        return response()->json([
            'message' => 'Teacher duty occurrence category created successfully.',
            'data' => $this->categoryResource($category),
        ], 201);
    }

    public function deactivate(
        DeactivateTeacherDutyOccurrenceCategoryRequest $request,
        string $category
    ): JsonResponse {
        $request->validated();

        $schoolId = $this->schoolId($request);
        $actorId = $this->userId($request);

        $categoryId = $this->teacherDutyResourceId($category);

        $this->teacherDutyOperation(
            fn () => TeacherDutyOccurrenceCategory::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereKey($categoryId)
                ->firstOrFail()
        );

        $deactivated = $this->teacherDutyOperation(
            fn () => $this->service->deactivateCategory(
                $schoolId,
                $categoryId,
                $actorId
            )
        );

        $this->audit(
            $request,
            self::MODULE,
            'Deactivate',
            $deactivated,
            null,
            $this->categoryAuditValues($deactivated),
            'Deactivated Teacher duty occurrence category.'
        );

        return response()->json([
            'message' => 'Teacher duty occurrence category deactivated successfully.',
            'data' => $this->categoryResource($deactivated),
        ]);
    }

    private function teacherDutyResourceId(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages([
                'resource' => [
                    'The requested teacher duty resource is unavailable.',
                ],
            ]);
        }

        return $id;
    }

    private function teacherDutyOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'resource' => [
                    'The requested teacher duty resource is unavailable.',
                ],
            ]);
        }
    }

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->school_id, 403);

        $schoolId = (string) $user->school_id;
        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId === null
            || (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403);
        }

        return $schoolId;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);

        return (string) $user->id;
    }

    private function categoryResource(
        TeacherDutyOccurrenceCategory $category
    ): array {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'description' => $category->description,
            'is_canonical' => (bool) $category->is_canonical,
            'display_order' => $category->display_order,
            'active' => (bool) $category->active,
            'created_by' => $category->created_by,
            'deactivated_by' => $category->deactivated_by,
            'deactivated_at' => $category->deactivated_at,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    private function categoryAuditValues(
        TeacherDutyOccurrenceCategory $category
    ): array {
        return [
            'code' => $category->code,
            'name' => $category->name,
            'description' => $category->description,
            'is_canonical' => (bool) $category->is_canonical,
            'display_order' => $category->display_order,
            'active' => (bool) $category->active,
            'deactivated_at' => $category->deactivated_at,
        ];
    }
}
